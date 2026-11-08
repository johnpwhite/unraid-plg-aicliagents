#!/bin/bash
# Give one restricted run internet access without LAN, host, Docker or VM access.
set -euo pipefail
# CI test switch: a non-root process honours it; root honours it only with the CI marker file (a root-owned regular file that production never has).
if [[ "${AICLI_TEST_TMP:-}" == 1 ]] && ((EUID == 0)) && ! [[ -f /run/aicli-ci-test-marker && ! -L /run/aicli-ci-test-marker && -O /run/aicli-ci-test-marker ]]; then
    unset AICLI_TEST_TMP
    printf "%s\n" "aicli-run-network: the test switch is ignored for root without the CI marker" >&2
fi

default_path='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
if [[ "${AICLI_TEST_TMP:-0}" != 1 ]]; then PATH=$default_path; else PATH=${PATH:-$default_path}; fi
export PATH

action=${1:-}
id=${2:-}
[[ "$id" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { echo 'aicli-run-network: invalid workspace id' >&2; exit 64; }
hash=$(printf '%s' "$id" | sha256sum)
hash=${hash%% *}
tag=${hash:0:10}
netns="aicli-$tag"
host_if="arh${tag}"
guest_if="arn${tag}"
chain="AICLI_RUN_${tag^^}"
nat_chain="AICLI_NAT_${tag^^}"
pre_chain="AICLI_PRE_${tag^^}"
pre6_chain="AICLI_P6_${tag^^}"
host_ip='198.18.0.1/30'
guest_ip='198.18.0.2/30'
guest_cidr='198.18.0.2/32'
netns_root=/var/run/netns
etc_netns=/etc/netns
ip_forward_file=/proc/sys/net/ipv4/ip_forward
if [[ -n "${AICLI_NETNS_ROOT:-}${AICLI_ETC_NETNS_ROOT:-}${AICLI_RUN_IP_FORWARD_FILE:-}" ]]; then
    [[ "${AICLI_TEST_TMP:-0}" == 1 ]] || { echo 'aicli-run-network: test paths need the CI test marker' >&2; exit 70; }
    netns_root=${AICLI_NETNS_ROOT:-$netns_root}
    etc_netns=${AICLI_ETC_NETNS_ROOT:-$etc_netns}
    ip_forward_file=${AICLI_RUN_IP_FORWARD_FILE:-$ip_forward_file}
fi
netns_file="$netns_root/$netns"
router_ip_file="$etc_netns/$netns/router-ipv4"

fail() { echo "aicli-run-network: $*" >&2; exit 70; }
ipt() { iptables -w 5 "$@"; }
ip6t() { ip6tables -w 5 "$@"; }

valid_ipv4() {
    local raw=${1:-} a b c d extra
    [[ "$raw" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
    IFS=. read -r a b c d extra <<< "$raw"
    [[ -z "${extra:-}" && ${#a} -le 3 && ${#b} -le 3 && ${#c} -le 3 && ${#d} -le 3 ]] || return 1
    ((10#$a <= 255 && 10#$b <= 255 && 10#$c <= 255 && 10#$d <= 255))
}

query_public_ip() {
    command -v curl >/dev/null || return 1
    local raw a b c d
    raw=$(curl -4 --fail --silent --show-error --connect-timeout 3 --max-time 5 --noproxy '*' https://api.ipify.org/) || return 1
    valid_ipv4 "$raw" || return 1
    IFS=. read -r a b c d <<< "$raw"
    printf '%d.%d.%d.%d\n' "$((10#$a))" "$((10#$b))" "$((10#$c))" "$((10#$d))"
}

save_router_ip() {
    local value=$1 tmp="$router_ip_file.tmp.$$"
    (umask 077; printf '%s\n' "$value" > "$tmp") || return 1
    chmod 0644 "$tmp" && mv -f -- "$tmp" "$router_ip_file" || { rm -f -- "$tmp"; return 1; }
}

ensure_rule_first() {
    local tool=$1 table=$2 builtin=$3 expected current rules inserted=0
    shift 3
    expected="-A $builtin"
    local part
    for part in "$@"; do expected+=" $part"; done
    if [[ "$table" == filter ]]; then rules=$("$tool" -w 5 -S "$builtin" 2>/dev/null) || rules=''
    else rules=$("$tool" -w 5 -t "$table" -S "$builtin" 2>/dev/null) || rules=''
    fi
    current=$(awk 'NR == 2 {print; exit}' <<< "$rules")
    if [[ "$current" != "$expected" ]]; then
        if [[ "$table" == filter ]]; then "$tool" -w 5 -I "$builtin" 1 "$@" || return 1
        else "$tool" -w 5 -t "$table" -I "$builtin" 1 "$@" || return 1
        fi
        inserted=1
    fi
    # Keep the new or current rule at position 1. Remove duplicate copies from the bottom up so the guard never has a gap.
    local -a positions=()
    local idx line first_duplicate=1
    mapfile -t positions < <(awk -v expected="$expected" '$0 == expected {print NR - 1}' <<< "$rules")
    if ((inserted == 1)); then first_duplicate=0; fi
    for ((idx=${#positions[@]} - 1; idx >= first_duplicate; idx--)); do
        line=$((positions[idx] + inserted))
        if [[ "$table" == filter ]]; then "$tool" -w 5 -D "$builtin" "$line" || return 1
        else "$tool" -w 5 -t "$table" -D "$builtin" "$line" || return 1
        fi
    done
    [[ "$current" == "$expected" || "$inserted" == 1 ]] && return 0
    return 1
}

route_dev() {
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}'
}

block_mangle_dest() {
    ipt -t mangle -A "$pre_chain" -d "$1" -j DROP || fail "a local destination could not be blocked before translation: $1"
}

remove_rules() {
    while ipt -C INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable 2>/dev/null; do
        ipt -D INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable || return 1
    done
    while ipt -C FORWARD -i "$host_if" -j "$chain" 2>/dev/null; do ipt -D FORWARD -i "$host_if" -j "$chain" || return 1; done
    while ipt -t nat -C POSTROUTING -s "$guest_cidr" -j "$nat_chain" 2>/dev/null; do
        ipt -t nat -D POSTROUTING -s "$guest_cidr" -j "$nat_chain" || return 1
    done
    while ipt -t mangle -C PREROUTING -i "$host_if" -j "$pre_chain" 2>/dev/null; do
        ipt -t mangle -D PREROUTING -i "$host_if" -j "$pre_chain" || return 1
    done
    while ip6t -t mangle -C PREROUTING -i "$host_if" -j "$pre6_chain" 2>/dev/null; do
        ip6t -t mangle -D PREROUTING -i "$host_if" -j "$pre6_chain" || return 1
    done
    while ip6t -C INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable 2>/dev/null; do
        ip6t -D INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable || return 1
    done
    while ip6t -C FORWARD -i "$host_if" -j REJECT 2>/dev/null; do ip6t -D FORWARD -i "$host_if" -j REJECT || return 1; done
    ipt -F "$chain" 2>/dev/null || true
    ipt -X "$chain" 2>/dev/null || true
    ipt -t nat -F "$nat_chain" 2>/dev/null || true
    ipt -t nat -X "$nat_chain" 2>/dev/null || true
    ipt -t mangle -F "$pre_chain" 2>/dev/null || true
    ipt -t mangle -X "$pre_chain" 2>/dev/null || true
    ip6t -t mangle -F "$pre6_chain" 2>/dev/null || true
    ip6t -t mangle -X "$pre6_chain" 2>/dev/null || true
}

cleanup_partial() {
    local rc=$?
    trap - EXIT
    if ((rc != 0)) && [[ "${cleanup_active:-0}" == 1 ]]; then
        remove_rules || true
        ip link delete "$host_if" 2>/dev/null || true
        ip netns delete "$netns" 2>/dev/null || true
        rm -f "$etc_netns/$netns/resolv.conf" "$router_ip_file"
        rmdir "$etc_netns/$netns" 2>/dev/null || true
    fi
    exit "$rc"
}

cleanup_refresh() {
    local rc=$?
    trap - EXIT
    if ((rc != 0)) && [[ "${refresh_active:-0}" == 1 ]]; then
        # If the WAN address cannot be checked or a guard cannot be restored, block all IPv4 from this veth until cleanup.
        ipt -t mangle -I "$pre_chain" 1 -j DROP 2>/dev/null || true
    fi
    exit "$rc"
}

case "$action" in
    prepare)
        command -v ip >/dev/null || fail 'iproute2 is missing'
        command -v iptables >/dev/null || fail 'iptables is missing'
        command -v curl >/dev/null || fail 'curl is missing for the router-address guard'
        [[ -r "$ip_forward_file" && $(cat "$ip_forward_file") == 1 ]] || fail 'IPv4 forwarding is disabled'
        public_ip=$(query_public_ip) || fail 'the router public address could not be checked'
        out_if=$(route_dev)
        [[ -n "$out_if" ]] || fail 'there is no IPv4 route to the internet'
        ip link show "$out_if" >/dev/null 2>&1 || fail 'the internet route uses an unknown interface'
        [[ ! -e "$netns_file" ]] || fail 'the workspace network namespace already exists'
        ! ip link show "$host_if" >/dev/null 2>&1 || fail 'the private host link already exists'
        ! ipt -S "$chain" >/dev/null 2>&1 || fail 'the workspace firewall chain already exists'
        ! ipt -t nat -S "$nat_chain" >/dev/null 2>&1 || fail 'the workspace address-translation chain already exists'
        ! ipt -t mangle -S "$pre_chain" >/dev/null 2>&1 || fail 'the workspace pre-routing guard already exists'
        command -v ip6tables >/dev/null || fail 'IPv6 firewall tools are missing'
        ! ip6t -t mangle -S "$pre6_chain" >/dev/null 2>&1 || fail 'the workspace IPv6 pre-routing guard already exists'
        cleanup_active=1
        trap cleanup_partial EXIT
        mkdir -p "$netns_root" "$etc_netns/$netns" || fail 'the network namespace folders could not be made'
        printf 'nameserver 1.1.1.1\nnameserver 9.9.9.9\noptions timeout:2 attempts:2\n' > "$etc_netns/$netns/resolv.conf"
        chmod 0644 "$etc_netns/$netns/resolv.conf"
        save_router_ip "$public_ip" || fail 'the router public address could not be saved for refresh'
        ip netns add "$netns" || fail 'the private network namespace could not be made'
        ip link add "$host_if" type veth peer name "$guest_if" || fail 'the private network link could not be made'
        ip link set "$guest_if" netns "$netns" || fail 'the private network link could not enter its namespace'
        ip address add "$host_ip" dev "$host_if" || fail 'the host side of the private link could not be addressed'
        ip link set "$host_if" up || fail 'the host side of the private link could not start'
        ip netns exec "$netns" ip address add "$guest_ip" dev "$guest_if" || fail 'the run side of the private link could not be addressed'
        ip netns exec "$netns" ip link set "$guest_if" up || fail 'the run side of the private link could not start'
        ip netns exec "$netns" ip link set lo up || fail 'loopback could not start in the private network namespace'
        ip netns exec "$netns" ip route add default via 198.18.0.1 || fail 'the internet route could not be made'

        ipt -N "$chain" || fail 'the workspace firewall chain could not be made'
        ipt -t nat -N "$nat_chain" || fail 'the workspace address-translation chain could not be made'
        ipt -t mangle -N "$pre_chain" || fail 'the pre-routing guard could not be made'
        ip6t -t mangle -N "$pre6_chain" || fail 'the IPv6 pre-routing guard could not be made'
        ensure_rule_first iptables mangle "$pre_chain" -d "$public_ip/32" -j DROP || fail 'the router public address could not be blocked before destination translation'
        ip6t -t mangle -A "$pre6_chain" -j DROP || fail 'IPv6 traffic could not be blocked before destination translation'
        for subnet in 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24 192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24 224.0.0.0/4 240.0.0.0/4; do
            block_mangle_dest "$subnet"
        done
        while IFS= read -r address; do
            [[ -n "$address" ]] || continue
            block_mangle_dest "$address"
        done < <(ip -4 route show table local | awk '$1 == "local" {print $2}')
        while IFS= read -r subnet; do
            [[ -n "$subnet" && "$subnet" != default ]] || continue
            block_mangle_dest "$subnet"
        done < <(ip -4 route show table all | awk '$1 == "unicast" {print $2; next} $1 !~ /^(default|local|broadcast|multicast|unreachable|prohibit|blackhole|throw)$/ {print $1}')
        ensure_rule_first iptables filter INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable || fail 'host access could not be blocked'
        ensure_rule_first iptables filter FORWARD -i "$host_if" -j "$chain" || fail 'the forwarding guard could not be installed'
        ensure_rule_first ip6tables filter INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable || fail 'IPv6 host access could not be blocked'
        ensure_rule_first ip6tables filter FORWARD -i "$host_if" -j REJECT || fail 'IPv6 forwarding could not be blocked'
        ensure_rule_first iptables mangle PREROUTING -i "$host_if" -j "$pre_chain" || fail 'the pre-routing guard could not be installed'
        ensure_rule_first ip6tables mangle PREROUTING -i "$host_if" -j "$pre6_chain" || fail 'the IPv6 pre-routing guard could not be installed'
        for subnet in 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24 192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24 224.0.0.0/4 240.0.0.0/4; do
            ipt -A "$chain" -d "$subnet" -j REJECT || fail "the private destination guard failed for $subnet"
        done
        while IFS= read -r address; do
            [[ -n "$address" ]] || continue
            ipt -A "$chain" -d "$address" -j REJECT || fail "a host address could not be blocked: $address"
        done < <(ip -4 route show table local | awk '$1 == "local" {print $2}')
        while IFS= read -r subnet; do
            [[ -n "$subnet" && "$subnet" != default ]] || continue
            ipt -A "$chain" -d "$subnet" -j REJECT || fail "a local route could not be blocked: $subnet"
        done < <(ip -4 route show table all | awk '$1 == "unicast" {print $2; next} $1 !~ /^(default|local|broadcast|multicast|unreachable|prohibit|blackhole|throw)$/ {print $1}')
        ipt -A "$chain" -m conntrack --ctstate ESTABLISHED,RELATED -o "$out_if" -j ACCEPT || fail 'internet replies could not be allowed'
        ipt -A "$chain" -s "$guest_cidr" -o "$out_if" -j ACCEPT || fail 'internet access could not be allowed'
        ipt -A "$chain" -j REJECT || fail 'other network access could not be blocked'
        ipt -t nat -A "$nat_chain" -o "$out_if" -j MASQUERADE || fail 'internet address translation could not be set'
        ensure_rule_first iptables nat POSTROUTING -s "$guest_cidr" -j "$nat_chain" || fail 'internet address translation could not be connected'
        ipt -C INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable || fail 'host access guard verification failed'
        ipt -C FORWARD -i "$host_if" -j "$chain" || fail 'forwarding guard verification failed'
        ip6t -C INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable || fail 'IPv6 host access guard verification failed'
        ip6t -C FORWARD -i "$host_if" -j REJECT || fail 'IPv6 forwarding guard verification failed'
        ipt -t mangle -C PREROUTING -i "$host_if" -j "$pre_chain" || fail 'pre-routing guard verification failed'
        ip6t -t mangle -C PREROUTING -i "$host_if" -j "$pre6_chain" || fail 'IPv6 pre-routing guard verification failed'
        ipt -t mangle -C "$pre_chain" -d "$public_ip/32" -j DROP || fail 'router public address guard verification failed'
        ipt -t nat -C POSTROUTING -s "$guest_cidr" -j "$nat_chain" || fail 'internet address translation verification failed'
        cleanup_active=0
        trap - EXIT
        printf '%s\n' "$netns"
        ;;
    refresh)
        refresh_active=1
        trap cleanup_refresh EXIT
        command -v iptables >/dev/null || fail 'iptables is missing during guard refresh'
        command -v ip6tables >/dev/null || fail 'ip6tables is missing during guard refresh'
        command -v curl >/dev/null || fail 'curl is missing during router-address refresh'
        old_public_ip=''
        if [[ -f "$router_ip_file" && ! -L "$router_ip_file" ]]; then IFS= read -r old_public_ip < "$router_ip_file" || old_public_ip=''; fi
        valid_ipv4 "$old_public_ip" || old_public_ip=''
        if public_ip=$(query_public_ip); then
            ensure_rule_first iptables mangle "$pre_chain" -d "$public_ip/32" -j DROP || fail 'the router public address rule could not be refreshed'
            if [[ -n "$old_public_ip" && "$old_public_ip" != "$public_ip" ]] && ipt -t mangle -C "$pre_chain" -d "$old_public_ip/32" -j DROP 2>/dev/null; then
                ipt -t mangle -D "$pre_chain" -d "$old_public_ip/32" -j DROP || fail 'the old router address rule could not be removed after the new guard was installed'
            fi
            save_router_ip "$public_ip" || fail 'the refreshed router address could not be saved'
        else
            public_ip=$old_public_ip
            valid_ipv4 "$public_ip" || fail 'the router public address could not be checked and no last known address is available'
            ensure_rule_first iptables mangle "$pre_chain" -d "$public_ip/32" -j DROP || fail 'the last known router address rule could not be restored'
        fi
        ensure_rule_first iptables filter INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable || fail 'the host access guard is not at the top of INPUT'
        ensure_rule_first iptables filter FORWARD -i "$host_if" -j "$chain" || fail 'the forwarding guard is not at the top of FORWARD'
        ensure_rule_first ip6tables filter INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable || fail 'the IPv6 host access guard is not at the top of INPUT'
        ensure_rule_first ip6tables filter FORWARD -i "$host_if" -j REJECT || fail 'the IPv6 forwarding guard is not at the top of FORWARD'
        ensure_rule_first iptables mangle PREROUTING -i "$host_if" -j "$pre_chain" || fail 'the pre-routing guard is not at the top of PREROUTING'
        ensure_rule_first ip6tables mangle PREROUTING -i "$host_if" -j "$pre6_chain" || fail 'the IPv6 pre-routing guard is not at the top of PREROUTING'
        ensure_rule_first iptables nat POSTROUTING -s "$guest_cidr" -j "$nat_chain" || fail 'internet address translation is not at the top of POSTROUTING'
        ipt -C INPUT -i "$host_if" -j REJECT --reject-with icmp-port-unreachable || fail 'host access guard verification failed during refresh'
        ip6t -C INPUT -i "$host_if" -j REJECT --reject-with icmp6-port-unreachable || fail 'IPv6 host access guard verification failed during refresh'
        ipt -C FORWARD -i "$host_if" -j "$chain" || fail 'forwarding guard verification failed during refresh'
        ipt -t mangle -C PREROUTING -i "$host_if" -j "$pre_chain" || fail 'pre-routing guard verification failed during refresh'
        ipt -t mangle -C "$pre_chain" -d "$public_ip/32" -j DROP || fail 'router address verification failed during refresh'
        refresh_active=0
        trap - EXIT
        printf 'ready\n'
        ;;
    remove)
        command -v iptables >/dev/null || fail 'iptables is missing during cleanup'
        command -v ip6tables >/dev/null || fail 'ip6tables is missing during cleanup'
        remove_rules || fail 'workspace firewall rules could not be removed'
        ip link delete "$host_if" 2>/dev/null || true
        ip netns delete "$netns" 2>/dev/null || true
        rm -f "$etc_netns/$netns/resolv.conf" "$router_ip_file"
        rmdir "$etc_netns/$netns" 2>/dev/null || true
        ! ipt -C INPUT -i "$host_if" -j REJECT 2>/dev/null || fail 'workspace INPUT rule remains'
        ! ipt -C FORWARD -i "$host_if" -j "$chain" 2>/dev/null || fail 'workspace FORWARD rule remains'
        ! ipt -t nat -C POSTROUTING -s "$guest_cidr" -j "$nat_chain" 2>/dev/null || fail 'workspace NAT rule remains'
        ! ipt -t mangle -C PREROUTING -i "$host_if" -j "$pre_chain" 2>/dev/null || fail 'workspace pre-routing rule remains'
        ! ip6t -t mangle -C PREROUTING -i "$host_if" -j "$pre6_chain" 2>/dev/null || fail 'workspace IPv6 pre-routing rule remains'
        ! ip6t -C INPUT -i "$host_if" -j REJECT 2>/dev/null || fail 'workspace IPv6 INPUT rule remains'
        ! ip6t -C FORWARD -i "$host_if" -j REJECT 2>/dev/null || fail 'workspace IPv6 FORWARD rule remains'
        ! ipt -S "$chain" >/dev/null 2>&1 || fail 'workspace firewall chain remains'
        ! ipt -t nat -S "$nat_chain" >/dev/null 2>&1 || fail 'workspace NAT chain remains'
        ! ipt -t mangle -S "$pre_chain" >/dev/null 2>&1 || fail 'workspace pre-routing chain remains'
        ! ip6t -t mangle -S "$pre6_chain" >/dev/null 2>&1 || fail 'workspace IPv6 pre-routing chain remains'
        ! ip link show "$host_if" >/dev/null 2>&1 || fail 'workspace host link remains'
        [[ ! -e "$netns_file" ]] || fail 'workspace network namespace remains'
        ;;
    *) echo 'usage: aicli-run-network.sh {prepare|refresh|remove} <workspace-id>' >&2; exit 64 ;;
esac
