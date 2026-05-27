// aicli-secret-service — a minimal org.freedesktop.secrets (Secret Service)
// daemon for headless Unraid.
//
// Why this exists: agents such as Antigravity CLI store their OAuth token in
// the OS keyring via the freedesktop Secret Service D-Bus API. A headless
// Unraid server ships no Secret Service provider, so those agents cannot
// persist credentials and prompt for re-auth every session (Bug #1042).
//
// This daemon implements the subset of the Secret Service API a keyring client
// needs (Service / Collection / Item / Session, the "plain" transport), backed
// by a single plaintext 0600 JSON file in the agent user's home overlay — the
// same persistence posture as the plugin's secrets.cfg. See
// docs/specs/SECRET_SERVICE.md.
package main

import (
	"encoding/json"
	"flag"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/godbus/dbus/v5"
	"github.com/godbus/dbus/v5/introspect"
)

const (
	busName   = "org.freedesktop.secrets"
	noPrompt  = dbus.ObjectPath("/")
	noObject  = dbus.ObjectPath("/")
	collLabel = "Login"

	servicePath = dbus.ObjectPath("/org/freedesktop/secrets")
	collPath    = dbus.ObjectPath("/org/freedesktop/secrets/collection/login")

	ifaceService    = "org.freedesktop.Secret.Service"
	ifaceCollection = "org.freedesktop.Secret.Collection"
	ifaceItem       = "org.freedesktop.Secret.Item"
	ifaceSession    = "org.freedesktop.Secret.Session"
	ifaceProps      = "org.freedesktop.DBus.Properties"
	ifaceIntro      = "org.freedesktop.DBus.Introspectable"

	propLabel      = "org.freedesktop.Secret.Item.Label"
	propAttributes = "org.freedesktop.Secret.Item.Attributes"
)

// secret is the D-Bus (oayays) Secret structure.
type secret struct {
	Session     dbus.ObjectPath
	Parameters  []byte
	Value       []byte
	ContentType string
}

// item is one stored secret. Marshalled to the on-disk keyring file.
type item struct {
	ID         string            `json:"id"`
	Label      string            `json:"label"`
	Attributes map[string]string `json:"attributes"`
	Secret     []byte            `json:"secret"`
	Content    string            `json:"content_type"`
	Created    uint64            `json:"created"`
	Modified   uint64            `json:"modified"`
}

type diskState struct {
	NextID int     `json:"next_id"`
	Items  []*item `json:"items"`
}

// daemon holds all server state. The mutex guards items/nextID/sessions.
type daemon struct {
	mu       sync.Mutex
	conn     *dbus.Conn
	storeF   string
	items    map[string]*item // id -> item
	nextID   int
	sessions map[dbus.ObjectPath]bool
	sessSeq  int
}

func now() uint64 { return uint64(time.Now().Unix()) }

func itemPath(id string) dbus.ObjectPath {
	return dbus.ObjectPath(string(collPath) + "/i" + id)
}

// ---------------------------------------------------------------- persistence

// load reads the keyring file. Missing file = empty keyring (first run).
func (d *daemon) load() {
	d.items = map[string]*item{}
	d.nextID = 1
	raw, err := os.ReadFile(d.storeF)
	if err != nil {
		if !os.IsNotExist(err) {
			log.Printf("load: %v (starting empty)", err)
		}
		return
	}
	var ds diskState
	if err := json.Unmarshal(raw, &ds); err != nil {
		log.Printf("load: corrupt keyring file: %v (starting empty)", err)
		return
	}
	for _, it := range ds.Items {
		if it == nil || it.ID == "" {
			continue
		}
		if it.Attributes == nil {
			it.Attributes = map[string]string{}
		}
		d.items[it.ID] = it
	}
	if ds.NextID > d.nextID {
		d.nextID = ds.NextID
	}
	log.Printf("loaded %d item(s) from %s", len(d.items), d.storeF)
}

// save atomically writes the keyring file (0600). Caller holds d.mu.
func (d *daemon) save() {
	ds := diskState{NextID: d.nextID}
	for _, it := range d.items {
		ds.Items = append(ds.Items, it)
	}
	raw, err := json.MarshalIndent(ds, "", "  ")
	if err != nil {
		log.Printf("save: marshal: %v", err)
		return
	}
	if err := os.MkdirAll(filepath.Dir(d.storeF), 0o700); err != nil {
		log.Printf("save: mkdir: %v", err)
		return
	}
	tmp := d.storeF + ".tmp"
	if err := os.WriteFile(tmp, raw, 0o600); err != nil {
		log.Printf("save: write: %v", err)
		return
	}
	if err := os.Rename(tmp, d.storeF); err != nil {
		log.Printf("save: rename: %v", err)
		_ = os.Remove(tmp)
		return
	}
}

// matches reports whether an item carries every query attribute with an equal
// value. An empty query matches every item.
func matches(it *item, query map[string]string) bool {
	for k, v := range query {
		if it.Attributes[k] != v {
			return false
		}
	}
	return true
}

// search returns the paths of all items matching the query. Caller holds d.mu.
func (d *daemon) search(query map[string]string) []dbus.ObjectPath {
	out := []dbus.ObjectPath{}
	for id, it := range d.items {
		if matches(it, query) {
			out = append(out, itemPath(id))
		}
	}
	return out
}

func (d *daemon) itemByPath(p dbus.ObjectPath) *item {
	prefix := string(collPath) + "/i"
	if !strings.HasPrefix(string(p), prefix) {
		return nil
	}
	return d.items[strings.TrimPrefix(string(p), prefix)]
}

// exportItem registers an item's D-Bus objects. Caller need not hold d.mu.
func (d *daemon) exportItem(id string) {
	p := itemPath(id)
	_ = d.conn.Export(&itemObj{d: d, id: id}, p, ifaceItem)
	_ = d.conn.Export(&props{d: d, path: p}, p, ifaceProps)
	_ = d.conn.Export(introspect.Introspectable(itemIntrospect), p, ifaceIntro)
}

func (d *daemon) unexportItem(id string) {
	p := itemPath(id)
	_ = d.conn.Export(nil, p, ifaceItem)
	_ = d.conn.Export(nil, p, ifaceProps)
	_ = d.conn.Export(nil, p, ifaceIntro)
}

// ------------------------------------------------------------------- Service

type svc struct{ d *daemon }

func (s *svc) OpenSession(algorithm string, input dbus.Variant) (dbus.Variant, dbus.ObjectPath, *dbus.Error) {
	if algorithm != "plain" {
		// Encrypted transports (dh-ietf1024-…) are not implemented; clients
		// that honour the spec fall back to "plain".
		return dbus.MakeVariant(""), noObject,
			dbus.NewError("org.freedesktop.DBus.Error.NotSupported",
				[]interface{}{"only the plain transport is supported"})
	}
	s.d.mu.Lock()
	s.d.sessSeq++
	p := dbus.ObjectPath("/org/freedesktop/secrets/session/s" + strconv.Itoa(s.d.sessSeq))
	s.d.sessions[p] = true
	s.d.mu.Unlock()

	_ = s.d.conn.Export(&sessObj{d: s.d, path: p}, p, ifaceSession)
	_ = s.d.conn.Export(introspect.Introspectable(sessionIntrospect), p, ifaceIntro)
	return dbus.MakeVariant(""), p, nil
}

func (s *svc) CreateCollection(props map[string]dbus.Variant, alias string) (dbus.ObjectPath, dbus.ObjectPath, *dbus.Error) {
	// One collection only; hand back the existing default.
	return collPath, noPrompt, nil
}

func (s *svc) SearchItems(attributes map[string]string) ([]dbus.ObjectPath, []dbus.ObjectPath, *dbus.Error) {
	s.d.mu.Lock()
	defer s.d.mu.Unlock()
	return s.d.search(attributes), []dbus.ObjectPath{}, nil
}

func (s *svc) Unlock(objects []dbus.ObjectPath) ([]dbus.ObjectPath, dbus.ObjectPath, *dbus.Error) {
	// Nothing is ever locked — report every object as already unlocked.
	return objects, noPrompt, nil
}

func (s *svc) Lock(objects []dbus.ObjectPath) ([]dbus.ObjectPath, dbus.ObjectPath, *dbus.Error) {
	// Locking is a no-op on a headless single-user keyring.
	return []dbus.ObjectPath{}, noPrompt, nil
}

func (s *svc) GetSecrets(items []dbus.ObjectPath, session dbus.ObjectPath) (map[dbus.ObjectPath]secret, *dbus.Error) {
	s.d.mu.Lock()
	defer s.d.mu.Unlock()
	out := map[dbus.ObjectPath]secret{}
	for _, p := range items {
		if it := s.d.itemByPath(p); it != nil {
			out[p] = secret{Session: session, Parameters: []byte{}, Value: it.Secret, ContentType: it.Content}
		}
	}
	return out, nil
}

func (s *svc) ReadAlias(name string) (dbus.ObjectPath, *dbus.Error) {
	if name == "default" {
		return collPath, nil
	}
	return noObject, nil
}

func (s *svc) SetAlias(name string, collection dbus.ObjectPath) *dbus.Error {
	return nil
}

// ----------------------------------------------------------------- Collection

type coll struct{ d *daemon }

func (c *coll) SearchItems(attributes map[string]string) ([]dbus.ObjectPath, *dbus.Error) {
	c.d.mu.Lock()
	defer c.d.mu.Unlock()
	return c.d.search(attributes), nil
}

func (c *coll) CreateItem(properties map[string]dbus.Variant, sec secret, replace bool) (dbus.ObjectPath, dbus.ObjectPath, *dbus.Error) {
	label := ""
	attrs := map[string]string{}
	if v, ok := properties[propLabel]; ok {
		if s, ok := v.Value().(string); ok {
			label = s
		}
	}
	if v, ok := properties[propAttributes]; ok {
		if m, ok := v.Value().(map[string]string); ok {
			attrs = m
		}
	}

	c.d.mu.Lock()
	var target *item
	if replace {
		for _, it := range c.d.items {
			if len(it.Attributes) == len(attrs) && matches(it, attrs) {
				target = it
				break
			}
		}
	}
	created := false
	if target == nil {
		id := strconv.Itoa(c.d.nextID)
		c.d.nextID++
		target = &item{ID: id, Attributes: attrs, Created: now()}
		c.d.items[id] = target
		created = true
	}
	target.Label = label
	target.Attributes = attrs
	target.Secret = append([]byte(nil), sec.Value...)
	target.Content = sec.ContentType
	if target.Content == "" {
		target.Content = "text/plain"
	}
	target.Modified = now()
	id := target.ID
	c.d.save()
	c.d.mu.Unlock()

	p := itemPath(id)
	if created {
		c.d.exportItem(id)
		_ = c.d.conn.Emit(collPath, ifaceCollection+".ItemCreated", p)
	} else {
		_ = c.d.conn.Emit(collPath, ifaceCollection+".ItemChanged", p)
	}
	return p, noPrompt, nil
}

func (c *coll) Delete() (dbus.ObjectPath, *dbus.Error) {
	// The single default collection is not deletable.
	return noPrompt, nil
}

// ----------------------------------------------------------------------- Item

type itemObj struct {
	d  *daemon
	id string
}

func (i *itemObj) GetSecret(session dbus.ObjectPath) (secret, *dbus.Error) {
	i.d.mu.Lock()
	defer i.d.mu.Unlock()
	it := i.d.items[i.id]
	if it == nil {
		return secret{}, dbus.NewError("org.freedesktop.Secret.Error.NoSuchObject", nil)
	}
	return secret{Session: session, Parameters: []byte{}, Value: it.Secret, ContentType: it.Content}, nil
}

func (i *itemObj) SetSecret(sec secret) *dbus.Error {
	i.d.mu.Lock()
	it := i.d.items[i.id]
	if it == nil {
		i.d.mu.Unlock()
		return dbus.NewError("org.freedesktop.Secret.Error.NoSuchObject", nil)
	}
	it.Secret = append([]byte(nil), sec.Value...)
	it.Content = sec.ContentType
	if it.Content == "" {
		it.Content = "text/plain"
	}
	it.Modified = now()
	i.d.save()
	i.d.mu.Unlock()
	_ = i.d.conn.Emit(collPath, ifaceCollection+".ItemChanged", itemPath(i.id))
	return nil
}

func (i *itemObj) Delete() (dbus.ObjectPath, *dbus.Error) {
	i.d.mu.Lock()
	_, ok := i.d.items[i.id]
	delete(i.d.items, i.id)
	if ok {
		i.d.save()
	}
	i.d.mu.Unlock()
	if ok {
		i.d.unexportItem(i.id)
		_ = i.d.conn.Emit(collPath, ifaceCollection+".ItemDeleted", itemPath(i.id))
	}
	return noPrompt, nil
}

// -------------------------------------------------------------------- Session

type sessObj struct {
	d    *daemon
	path dbus.ObjectPath
}

func (s *sessObj) Close() *dbus.Error {
	s.d.mu.Lock()
	delete(s.d.sessions, s.path)
	s.d.mu.Unlock()
	_ = s.d.conn.Export(nil, s.path, ifaceSession)
	_ = s.d.conn.Export(nil, s.path, ifaceIntro)
	return nil
}

// ----------------------------------------------------------------- Properties

type props struct {
	d    *daemon
	path dbus.ObjectPath
}

func (p *props) values(iface string) map[string]dbus.Variant {
	out := map[string]dbus.Variant{}
	switch {
	case p.path == servicePath && iface == ifaceService:
		out["Collections"] = dbus.MakeVariant([]dbus.ObjectPath{collPath})
	case p.path == collPath && iface == ifaceCollection:
		p.d.mu.Lock()
		items := make([]dbus.ObjectPath, 0, len(p.d.items))
		var modified uint64
		for id, it := range p.d.items {
			items = append(items, itemPath(id))
			if it.Modified > modified {
				modified = it.Modified
			}
		}
		p.d.mu.Unlock()
		out["Items"] = dbus.MakeVariant(items)
		out["Label"] = dbus.MakeVariant(collLabel)
		out["Locked"] = dbus.MakeVariant(false)
		out["Created"] = dbus.MakeVariant(uint64(0))
		out["Modified"] = dbus.MakeVariant(modified)
	case iface == ifaceItem:
		p.d.mu.Lock()
		it := p.d.itemByPath(p.path)
		if it != nil {
			out["Label"] = dbus.MakeVariant(it.Label)
			out["Attributes"] = dbus.MakeVariant(it.Attributes)
			out["Locked"] = dbus.MakeVariant(false)
			out["Created"] = dbus.MakeVariant(it.Created)
			out["Modified"] = dbus.MakeVariant(it.Modified)
		}
		p.d.mu.Unlock()
	}
	return out
}

func (p *props) Get(iface, name string) (dbus.Variant, *dbus.Error) {
	v, ok := p.values(iface)[name]
	if !ok {
		return dbus.Variant{}, dbus.NewError("org.freedesktop.DBus.Error.UnknownProperty", []interface{}{name})
	}
	return v, nil
}

func (p *props) GetAll(iface string) (map[string]dbus.Variant, *dbus.Error) {
	return p.values(iface), nil
}

func (p *props) Set(iface, name string, value dbus.Variant) *dbus.Error {
	// The only writable property worth honouring is an item's Label.
	if iface == ifaceItem && name == "Label" {
		if s, ok := value.Value().(string); ok {
			p.d.mu.Lock()
			if it := p.d.itemByPath(p.path); it != nil {
				it.Label = s
				it.Modified = now()
				p.d.save()
			}
			p.d.mu.Unlock()
		}
		return nil
	}
	return nil
}

// --------------------------------------------------------------------- main

func main() {
	storeF := flag.String("store", "", "path to the keyring JSON file (required in server mode)")
	busAddr := flag.String("bus", "", "D-Bus session bus address (defaults to $DBUS_SESSION_BUS_ADDRESS)")
	pidFile := flag.String("pidfile", "", "write the daemon PID to this file once the bus name is owned")
	selftest := flag.Bool("selftest", false, "act as a client and round-trip a secret against the running daemon, then exit")
	flag.Parse()
	log.SetPrefix("aicli-secret-service: ")
	log.SetFlags(log.LstdFlags | log.LUTC)

	if *selftest {
		runSelftest(*busAddr)
		return
	}

	if *storeF == "" {
		log.Fatal("--store is required")
	}

	var conn *dbus.Conn
	var err error
	if *busAddr != "" {
		conn, err = dbus.Connect(*busAddr)
	} else {
		conn, err = dbus.ConnectSessionBus()
	}
	if err != nil {
		log.Fatalf("connect to session bus: %v", err)
	}
	defer conn.Close()

	reply, err := conn.RequestName(busName, dbus.NameFlagDoNotQueue)
	if err != nil {
		log.Fatalf("request name: %v", err)
	}
	if reply != dbus.RequestNameReplyPrimaryOwner {
		// Another provider already owns the name — nothing to do.
		log.Printf("%s already owned — exiting", busName)
		return
	}

	if *pidFile != "" {
		if err := os.WriteFile(*pidFile, []byte(strconv.Itoa(os.Getpid())), 0o600); err != nil {
			log.Printf("pidfile: %v", err)
		} else {
			defer os.Remove(*pidFile)
		}
	}

	d := &daemon{conn: conn, storeF: *storeF, sessions: map[dbus.ObjectPath]bool{}}
	d.load()

	if err := conn.Export(&svc{d}, servicePath, ifaceService); err != nil {
		log.Fatalf("export service: %v", err)
	}
	_ = conn.Export(&props{d: d, path: servicePath}, servicePath, ifaceProps)
	_ = conn.Export(introspect.Introspectable(serviceIntrospect), servicePath, ifaceIntro)

	if err := conn.Export(&coll{d}, collPath, ifaceCollection); err != nil {
		log.Fatalf("export collection: %v", err)
	}
	_ = conn.Export(&props{d: d, path: collPath}, collPath, ifaceProps)
	_ = conn.Export(introspect.Introspectable(collectionIntrospect), collPath, ifaceIntro)

	for id := range d.items {
		d.exportItem(id)
	}

	log.Printf("ready — %s owned on the session bus, store=%s", busName, d.storeF)

	// Block until the bus connection drops; godbus closes the signal channel
	// on disconnect. Exiting then lets the launcher restart bus + daemon.
	sig := make(chan *dbus.Signal, 16)
	conn.Signal(sig)
	for range sig {
	}
	log.Print("session bus disconnected — exiting")
}
