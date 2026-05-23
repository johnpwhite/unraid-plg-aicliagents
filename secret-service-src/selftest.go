package main

// --selftest mode: act as a Secret Service *client* and round-trip a secret
// (OpenSession -> CreateItem -> SearchItems -> GetSecret) against whatever
// daemon owns org.freedesktop.secrets on the session bus. Used by the smoke
// suite and localtest.sh to prove the service end-to-end. Prints
// "SELFTEST PASS" and exits 0 on success.

import (
	"bytes"
	"fmt"
	"os"

	"github.com/godbus/dbus/v5"
)

func stFail(stage string, err error) {
	fmt.Printf("SELFTEST FAIL: %s: %v\n", stage, err)
	os.Exit(1)
}

func runSelftest(busAddr string) {
	var conn *dbus.Conn
	var err error
	if busAddr != "" {
		conn, err = dbus.Connect(busAddr)
	} else {
		conn, err = dbus.ConnectSessionBus()
	}
	if err != nil {
		stFail("connect session bus", err)
	}
	defer conn.Close()

	svcObj := conn.Object(busName, servicePath)

	var output dbus.Variant
	var session dbus.ObjectPath
	if err := svcObj.Call(ifaceService+".OpenSession", 0, "plain", dbus.MakeVariant("")).Store(&output, &session); err != nil {
		stFail("OpenSession", err)
	}

	attrs := map[string]string{"service": "aicli-selftest", "account": "smoke"}
	want := []byte("aicli-secret-service-selftest-value")

	collObj := conn.Object(busName, collPath)
	createProps := map[string]dbus.Variant{
		propLabel:      dbus.MakeVariant("aicli selftest"),
		propAttributes: dbus.MakeVariant(attrs),
	}
	sec := secret{Session: session, Parameters: []byte{}, Value: want, ContentType: "text/plain"}
	var itemP, promptP dbus.ObjectPath
	if err := collObj.Call(ifaceCollection+".CreateItem", 0, createProps, sec, true).Store(&itemP, &promptP); err != nil {
		stFail("CreateItem", err)
	}

	var unlocked, locked []dbus.ObjectPath
	if err := svcObj.Call(ifaceService+".SearchItems", 0, attrs).Store(&unlocked, &locked); err != nil {
		stFail("SearchItems", err)
	}
	if len(unlocked) == 0 {
		stFail("SearchItems", fmt.Errorf("created item not found"))
	}

	var got secret
	if err := conn.Object(busName, unlocked[0]).Call(ifaceItem+".GetSecret", 0, session).Store(&got); err != nil {
		stFail("GetSecret", err)
	}
	if !bytes.Equal(got.Value, want) {
		stFail("GetSecret", fmt.Errorf("secret value mismatch"))
	}

	fmt.Println("SELFTEST PASS")
	os.Exit(0)
}
