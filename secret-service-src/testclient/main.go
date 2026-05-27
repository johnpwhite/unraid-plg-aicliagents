// testclient — exercises the Secret Service daemon over D-Bus the way a real
// keyring client (e.g. Antigravity CLI) would: OpenSession(plain), CreateItem,
// SearchItems, GetSecret. Used by localtest.sh and the smoke suite.
//
//	roundtrip — create an item, search, get, verify the value
//	verify    — search + get + verify (item must already exist; tests persistence)
package main

import (
	"bytes"
	"fmt"
	"os"

	"github.com/godbus/dbus/v5"
)

type secret struct {
	Session     dbus.ObjectPath
	Parameters  []byte
	Value       []byte
	ContentType string
}

const (
	busName  = "org.freedesktop.secrets"
	svcPath  = "/org/freedesktop/secrets"
	collPath = "/org/freedesktop/secrets/collection/login"
)

func fail(msg string, err error) {
	fmt.Fprintf(os.Stderr, "FAIL: %s: %v\n", msg, err)
	os.Exit(1)
}

func main() {
	mode := "roundtrip"
	if len(os.Args) > 1 {
		mode = os.Args[1]
	}

	conn, err := dbus.ConnectSessionBus()
	if err != nil {
		fail("connect session bus", err)
	}
	defer conn.Close()

	svc := conn.Object(busName, svcPath)

	var output dbus.Variant
	var session dbus.ObjectPath
	err = svc.Call("org.freedesktop.Secret.Service.OpenSession", 0, "plain", dbus.MakeVariant("")).Store(&output, &session)
	if err != nil {
		fail("OpenSession", err)
	}
	fmt.Println("OpenSession ok — session", session)

	attrs := map[string]string{"service": "aicli-selftest", "account": "antigravity"}
	want := []byte("super-secret-token-value-12345")

	if mode == "roundtrip" {
		coll := conn.Object(busName, collPath)
		props := map[string]dbus.Variant{
			"org.freedesktop.Secret.Item.Label":      dbus.MakeVariant("aicli self-test"),
			"org.freedesktop.Secret.Item.Attributes": dbus.MakeVariant(attrs),
		}
		sec := secret{Session: session, Parameters: []byte{}, Value: want, ContentType: "text/plain"}
		var itemPath, prompt dbus.ObjectPath
		err = coll.Call("org.freedesktop.Secret.Collection.CreateItem", 0, props, sec, true).Store(&itemPath, &prompt)
		if err != nil {
			fail("CreateItem", err)
		}
		fmt.Println("CreateItem ok — item", itemPath)
	}

	var unlocked, locked []dbus.ObjectPath
	err = svc.Call("org.freedesktop.Secret.Service.SearchItems", 0, map[string]string{"service": "aicli-selftest"}).Store(&unlocked, &locked)
	if err != nil {
		fail("SearchItems", err)
	}
	if len(unlocked) == 0 {
		fail("SearchItems", fmt.Errorf("no items found — persistence or search broken"))
	}
	fmt.Println("SearchItems ok — found", len(unlocked), "item(s)")

	item := conn.Object(busName, unlocked[0])
	var got secret
	err = item.Call("org.freedesktop.Secret.Item.GetSecret", 0, session).Store(&got)
	if err != nil {
		fail("GetSecret", err)
	}
	if !bytes.Equal(got.Value, want) {
		fail("GetSecret", fmt.Errorf("value mismatch: got %q want %q", got.Value, want))
	}
	fmt.Println("GetSecret ok — value round-tripped intact")
	fmt.Println("PASS (" + mode + ")")
}
