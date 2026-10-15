# Chapter 9 — Networking

## What you'll learn

- How a container gets its network: namespaces, virtual Ethernet pairs, bridges and NAT
- The networks Docker creates for you: `bridge`, `host` and `none`
- Why you should always create your own networks, and how DNS-based service discovery works on them
- Network aliases, connecting a container to several networks, and internal networks
- What really happens when you publish a port, and why a server must listen on `0.0.0.0` inside the container
- What `localhost` means inside a container, and how to reach the host
- Sharing a network namespace between containers
- IPv6 and the firewall backends, briefly
- Snip talking to another container by name

## How a container is wired

Each container gets its own **network namespace**: its own network interfaces, IP addresses, routing table, firewall
rules and port space. Two containers can both listen on port 3000 without conflict, because each has its own port 3000.

For the default kind of network, a **bridge network**, Docker wires that namespace to the host like this:

```text
   container "snip"                 container "db"
  ┌─────────────────┐              ┌─────────────────┐
  │ eth0 172.18.0.3 │              │ eth0 172.18.0.2 │
  └────────┬────────┘              └────────┬────────┘
           │ veth pair                      │ veth pair
  ┌────────┴────────────────────────────────┴────────┐
  │   br-daccadd49e61  (a Linux bridge = a virtual   │   host network namespace
  │   switch)  gateway 172.18.0.1                    │
  └────────────────────────┬─────────────────────────┘
                           │  routing + NAT (iptables or nftables rules)
                    host's eth0 ──────▶ LAN / internet
```

- A **veth pair** is a virtual cable: one end is the container's `eth0`, the other end is plugged into a bridge on the
  host.
- The **bridge** is a virtual switch. Containers on the same bridge reach each other directly, by IP address.
- The bridge has an IP address (the **gateway**, `172.18.0.1` here), which is each container's default route.
- Outgoing traffic to the internet is **masqueraded** (source NAT) to the host's address, the same way a home router
  shares one public IP address.
- Incoming traffic only arrives through **published ports**, which add destination-NAT rules.

You can see the container's side of this:

```bash
docker run --rm --network snipnet alpine:3 sh -c 'ip -4 addr show eth0 | grep inet; ip route'
```

```text
    inet 172.18.0.5/16 brd 172.18.255.255 scope global eth0
default via 172.18.0.1 dev eth0
172.18.0.0/16 dev eth0 scope link  src 172.18.0.5
```

(`snipnet` is a network created below.) On Docker Desktop, all of this happens inside the Linux VM, and Desktop adds
its own layer to forward published ports from your Mac or Windows machine into the VM.

## The built-in networks

```bash
docker network ls
```

```text
NETWORK ID     NAME      DRIVER    SCOPE
2a943260b1d9   bridge    bridge    local
c9cbcc6abd99   host      host      local
abd1b6dc9e76   none      null      local
```

| Network | What the container gets | Use |
|---|---|---|
| `bridge` (the default bridge, `docker0`) | A private IP on `172.17.0.0/16`; NAT to the outside; **no DNS between containers** | What you get when you don't say `--network`. Fine for a single throwaway container |
| `host` | No network namespace: the host's interfaces and ports directly | Maximum network performance; tools that must see the host's network |
| `none` | Only a loopback interface | Batch jobs that must have no network access |
| your own bridge networks | Like `bridge`, plus DNS by container name, and isolation from other networks | Everything with more than one container |

### Why the default bridge is not enough

On the default `bridge` network, containers can reach each other only by IP address, and IP addresses change every
time a container is recreated:

```bash
docker run -d --name a1 alpine:3 sleep 300
docker run -d --name a2 alpine:3 sleep 300
docker exec a2 ping -c1 -W1 a1
```

```text
ping: bad address 'a1'
```

The default bridge is a legacy design (it once relied on `--link`, now deprecated). Every container started without
`--network` also lands on it, so unrelated containers can talk to each other. **Always create a network for your
application.** Compose does this automatically (chapter 10).

## User-defined networks and service discovery

```bash
docker network create snipnet
docker run -d --name db --network snipnet -e POSTGRES_PASSWORD=devpass postgres:18-alpine
docker run --rm --network snipnet alpine:3 sh -c 'cat /etc/resolv.conf; getent hosts db; ping -c1 db'
```

```text
nameserver 127.0.0.11
options timeout:2 attempts:3 ndots:0

172.18.0.2        db  db
PING db (172.18.0.2): 56 data bytes
64 bytes from 172.18.0.2: seq=0 ttl=64 time=0.857 ms
```

On a user-defined network, every container's `/etc/resolv.conf` points at **127.0.0.11**, Docker's **embedded DNS
server**. It answers queries for the names of containers on the same networks (and their aliases) and forwards
everything else to the host's DNS servers. So the `db` container is reachable as `db`, whatever IP address it happens
to have today. That's how a Node.js app finds its database:

```bash
docker run --rm --network snipnet node:24-alpine node -e 'require("node:dns").promises.lookup("db").then(console.log)'
```

```text
{ address: '172.18.0.2', family: 4 }
```

In your app, the database host is simply `db` (`postgres://snip:…@db:5432/snip`). This is **the** reason
applications in containers get their configuration from environment variables: `DATABASE_URL=postgres://…@db:5432/…`
in Docker, `…@localhost:5432/…` on a laptop without Docker, `…@prod-db.internal:5432/…` in production.

Other things user-defined networks give you:

- **Isolation.** Containers on different networks can't reach each other at all.
- **Live connect and disconnect.** `docker network connect` / `disconnect` without restarting the container.
- **Configurable subnets** (`docker network create --subnet 10.10.0.0/24 …`), useful when Docker's default ranges
  collide with your company's VPN.

```bash
docker network inspect snipnet -f '{{json .IPAM.Config}}'
docker network inspect snipnet -f '{{range .Containers}}{{.Name}} {{.IPv4Address}}{{"\n"}}{{end}}'
```

```text
[{"Subnet":"172.18.0.0/16","Gateway":"172.18.0.1"}]
db 172.18.0.2/16
```

### Network aliases and simple load spreading

A container can have extra DNS names on a network with `--network-alias`. Several containers can share an alias, and
DNS then returns all their addresses:

```bash
for n in w1 w2; do
  docker run -d --name $n --network snipnet --network-alias api node:24-alpine node -e '
    require("node:http").createServer((q, s) => s.end(require("node:os").hostname() + "\n")).listen(3000)'
done
docker run --rm --network snipnet alpine:3 sh -c 'getent ahosts api | awk "{print \$1}" | sort -u; for i in 1 2 3 4; do wget -qO- http://api:3000; done'
```

```text
172.18.0.3
172.18.0.4
afc11f832ef5
1a3a3e7f35b1
1a3a3e7f35b1
1a3a3e7f35b1
```

Both addresses are returned, in varying order, and clients that pick the first address spread their requests, but
not evenly: the four requests above went 1–3. DNS round-robin is a crude form of load balancing (clients and
libraries cache and order addresses as they like). It's what Compose relies on when you scale a service (chapter 11);
for real load balancing, put a reverse proxy in front (chapter 18).

### One container, several networks

A container can be attached to several networks, getting one interface and IP on each. That's how you build tiers:

```text
   frontend network              backend network (internal)
 ┌──────────────────────┐      ┌───────────────────────────┐
 │ proxy ◀──▶ api ◀─────┼──────┼─▶ api ◀──▶ db             │
 └──────────────────────┘      └───────────────────────────┘
   proxy can't reach db; db can't reach the internet
```

```bash
docker network create --internal backend
docker network connect backend db
docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}={{$v.IPAddress}} {{end}}' db
```

```text
backend=172.19.0.2 snipnet=172.18.0.2
```

An **internal** network (`--internal`) has no route to the outside world:

```bash
docker run --rm --network backend alpine:3 sh -c 'wget -q -T 3 -O /dev/null https://example.com && echo online || echo "no route out"'
```

```text
wget: bad address 'example.com'
no route out
```

Put databases and caches on an internal network and only the services that need them on both. If one of your
containers is compromised, it can't download tools or exfiltrate data directly from there, and nothing on the
frontend network can reach the database.

## Publishing ports, precisely

Chapter 2 introduced `-p`. Here's what it does: Docker adds a **destination NAT** rule to the host's firewall that
rewrites packets arriving for `host-ip:host-port` to `container-ip:container-port`, and (by default) also starts a small
`docker-proxy` process for cases the NAT rule doesn't cover, such as connections from the host's own loopback
interface. With `-p 127.0.0.1:3000:3000` on the `snipnet` network, the NAT rule looks like this:

```text
-A DOCKER -d 127.0.0.1/32 ! -i br-daccadd49e61 -p tcp -m tcp --dport 3000 -j DNAT --to-destination 172.18.0.3:3000
```

Consequences worth knowing:

- **Published ports are for traffic from outside Docker.** Containers on the same network talk to each other on the
  *container* port, by name: `http://snip:3000`, regardless of whether or how it's published. You don't need to
  publish a database's port for your app container to use it.
- **The rules are evaluated before the host's own firewall rules** for forwarded traffic, which is why `ufw` and
  simple `firewalld` setups don't block published ports (chapter 2). Bind to `127.0.0.1` for local-only services, or
  manage the `DOCKER-USER` chain (the place Docker reserves for your own rules).
- **Engine 29 can use nftables** instead of iptables for these rules (an experimental daemon setting,
  `"firewall-backend": "nftables"`). The behaviour is the same; the rules live in nftables tables named
  `docker-bridges`.

### The server must listen on all interfaces

Published traffic arrives on the container's `eth0`, addressed to its own IP. A server bound to `127.0.0.1` inside the
container only accepts connections on the container's own loopback interface:

```bash
docker run -d --name lo -p 3005:3000 node:24-alpine node -e '
  require("node:http").createServer((q, s) => s.end("hi\n")).listen(3000, "127.0.0.1")'
curl localhost:3005
docker exec lo wget -qO- http://127.0.0.1:3000
```

```text
curl: (56) Recv failure: Connection reset by peer
hi
```

The server works from inside its own container but is unreachable from outside. The error isn't "connection refused"
but a reset, because the port mapping itself works and only the last hop fails, which makes this confusing the first
time. Listen on `0.0.0.0` (or `::` for IPv4 and IPv6), which is Node's default when you don't pass a host. Many dev
servers default to `localhost`; for Vite, pass `--host 0.0.0.0`; for Next.js, `-H 0.0.0.0` (or `HOSTNAME=0.0.0.0`
for its standalone server).

## `localhost` is the container itself

Inside a container, `localhost` (`127.0.0.1`) is the container's own loopback interface, not your machine and not
other containers:

```bash
docker run -d --name snip --network snipnet -p 127.0.0.1:3000:3000 snip:1.2
docker run --rm --network snipnet node:24-alpine node -e 'fetch("http://snip:3000/healthz").then(r => r.text()).then(console.log)'
docker run --rm --network snipnet node:24-alpine node -e '
  fetch("http://localhost:3000/healthz").then(r => r.text()).then(console.log, e => console.log(e.cause?.code))'
```

```text
{"status":"ok"}
ECONNREFUSED
```

The most common networking mistake when moving an app into a container is leaving `DATABASE_URL=…@localhost:5432/…`
in its configuration: on your laptop the database was on localhost; in a container, nothing is.

### Reaching the host

Sometimes a container needs a service running directly on your machine: a database you installed natively, a dev
server, a debugger.

- **Docker Desktop** provides the name **`host.docker.internal`**, which resolves to your machine.
- **On Linux**, add it yourself. The special value `host-gateway` resolves to the host's address on the bridge:

  ```bash
  docker run --rm --add-host host.docker.internal:host-gateway alpine:3 sh -c '
    grep host.docker /etc/hosts; wget -qO- http://host.docker.internal:3456'
  ```

  ```text
  172.17.0.1	host.docker.internal
  hello from the host
  ```

  (Here a Node server was listening on port 3456 on the host, on all interfaces. A host service listening only on
  `127.0.0.1` is *not* reachable this way: from the container's point of view, the host is another machine.)

Using the same name everywhere means one `DATABASE_URL=…@host.docker.internal:5432/…` works on every developer's
machine. In Compose, it's `extra_hosts: ["host.docker.internal:host-gateway"]`.

## The `host` and `none` networks

With **`--network host`**, the container doesn't get a network namespace at all. It uses the host's interfaces
directly: a server listening on port 3000 is on the host's port 3000, `-p` is ignored, and `localhost` *is* the host:

```bash
docker run --rm --network host alpine:3 wget -qO- http://127.0.0.1:3456
```

```text
hello from the host
```

It's the fastest option (no bridge, no NAT) and occasionally necessary (software that needs to see the host's real
interfaces, some service-discovery protocols). The cost is isolation: port conflicts with the host, and the container
can reach everything the host can. On Docker Desktop, host networking applies to the VM, not your Mac or PC, unless you
enable Desktop's host networking feature.

With **`--network none`**, the container has only a loopback interface. Use it for jobs that process untrusted input
and should have no network at all:

```bash
docker run --rm --network none alpine:3 ip -4 addr | grep inet
```

```text
    inet 127.0.0.1/8 scope host lo
```

## Sharing a network namespace

`--network container:<name>` puts a new container into an *existing* container's network namespace. They share
interfaces, IP address and ports, so `localhost` is the same for both:

```bash
docker run --rm --network container:db alpine:3 sh -c 'ip -4 addr show eth0 | grep inet; nc -z 127.0.0.1 5432 && echo "postgres on localhost"'
```

```text
    inet 172.18.0.2/16 brd 172.18.255.255 scope global eth0
postgres on localhost
```

This is the same idea as a Kubernetes pod, where all containers share one network namespace, and it's a powerful
debugging technique: attach a container full of network tools (`tcpdump`, `curl`, `dig`) to a minimal container that
has none of them, and look at its traffic from the inside (chapter 19).

## IPv6

Docker networks are IPv4-only by default. Create a network with IPv6 enabled with `docker network create --ipv6 …`
(optionally `--subnet` with an IPv6 prefix), and Docker assigns each container an IPv6 address too; published ports
then also listen on the host's IPv6 addresses (`[::]:8080`). Whether IPv6 traffic reaches the outside world depends on
your host's IPv6 setup. If your users or upstream APIs are IPv6-only, test this early.

## Node.js details that matter in containers

- **Resolve names at connection time, and retry.** When a dependency is replaced, its IP address changes. Node doesn't
  cache DNS results itself, so connection pools that reconnect pick up the new address. Long-lived single connections
  should reconnect on error rather than crash.
- **`localhost` may resolve to `::1` first.** Node 17+ uses the order the resolver returns, which on many systems puts
  IPv6 first. Inside a container that's harmless, but a client connecting to `localhost:3000` where the server listens
  only on IPv4 `0.0.0.0` gets `ECONNREFUSED ::1:3000`. Use `127.0.0.1` explicitly in health checks, as Snip's does.
- **Dependencies start in any order.** Your app may start before the database accepts connections. Retry with a
  backoff instead of crashing at the first `ECONNREFUSED`; Compose's health-check ordering (chapter 11) helps but
  doesn't replace retries.

## Key takeaways

- Each container has its own network namespace, connected through a veth pair to a bridge; outbound traffic is NAT-ed,
  inbound traffic arrives only through published ports.
- Always create your own networks. On them, Docker's embedded DNS (`127.0.0.11`) resolves container names and aliases;
  the default `bridge` network doesn't.
- Containers on the same network talk on the container port, by name. Publish ports only for traffic from outside
  Docker, and bind local-only services to `127.0.0.1`.
- A server in a container must listen on `0.0.0.0` (Node's default); `localhost` inside a container is the container
  itself.
- `host.docker.internal` reaches the host (built in on Docker Desktop; `--add-host host.docker.internal:host-gateway`
  on Linux).
- `--internal` networks have no outside access; attach containers to several networks to build tiers.
- `--network host` removes network isolation; `--network none` removes networking; `--network container:x` shares a
  namespace, which is great for debugging.

## Exercises

1. **Name resolution.** Start `nginx:alpine` named `web` on the default bridge and on a new network `lab`. From a
   second container, try to reach it by name on each network. Explain the results, then remove everything you created.

<details>
<summary>Solution</summary>

```bash
docker run -d --name web nginx:alpine
docker run --rm alpine:3 wget -qO- http://web          # wget: bad address 'web'
docker rm -f web

docker network create lab
docker run -d --name web --network lab nginx:alpine
docker run --rm --network lab alpine:3 wget -qO- http://web | head -4   # the nginx welcome page
docker rm -f web && docker network rm lab
```

The default bridge has no embedded DNS for container names; a user-defined network does.

</details>

2. **Three tiers.** Create two networks, `front` and `back` (internal). Run `postgres:18-alpine` as `db` on `back`,
   a container `api` (any image, for example `node:24-alpine sleep 600`) on both, and `proxy` (`alpine:3 sleep 600`) on
   `front` only. Verify: `api` reaches `db`; `proxy` reaches `api` but not `db`; `db` can't reach the internet.

<details>
<summary>Solution</summary>

```bash
docker network create front
docker network create --internal back
docker run -d --name db --network back -e POSTGRES_PASSWORD=x postgres:18-alpine
docker run -d --name api --network front node:24-alpine sleep 600
docker network connect back api
docker run -d --name proxy --network front alpine:3 sleep 600

docker exec api sh -c 'nc -z db 5432 && echo "api → db ok"'
docker exec proxy sh -c 'ping -c1 -W1 api >/dev/null && echo "proxy → api ok"'
docker exec proxy sh -c 'nc -z -w 2 db 5432 || echo "proxy → db blocked"'     # name doesn't even resolve
docker exec db sh -c 'wget -q -T 3 -O /dev/null https://example.com || echo "db → internet blocked"'

docker rm -f db api proxy && docker network rm front back
```

`proxy` can't even resolve `db`: DNS answers only with containers that share a network with the asker.

</details>

3. **The loopback trap.** Run a Node server that listens on `127.0.0.1:3000` inside a container with `-p 3000:3000`.
   Show that `curl` from the host fails, that `docker exec` + `wget` inside works, and fix it without rebuilding
   anything (only the command line).

<details>
<summary>Solution</summary>

```bash
docker run -d --name lo -p 3000:3000 node:24-alpine node -e '
  const host = process.env.HOST ?? "127.0.0.1";
  require("node:http").createServer((q, s) => s.end("hi\n")).listen(3000, host)'
curl localhost:3000                                  # Recv failure: Connection reset by peer
docker exec lo wget -qO- http://127.0.0.1:3000       # hi
docker rm -f lo
docker run -d --name lo -p 3000:3000 -e HOST=0.0.0.0 node:24-alpine node -e '…same…'
curl localhost:3000                                  # hi
```

This is why a server's bind address should be configurable through the environment (or simply default to all
interfaces).

</details>

4. **Debug from the side.** Snip's distroless image has no shell or network tools. Run `snip:1.2` named `snip`, then,
   without changing it, list its listening sockets and make an HTTP request to it *over its own loopback interface*.

<details>
<summary>Solution</summary>

Join its network namespace with a container that has tools:

```bash
docker run -d --name snip snip:1.2
docker run --rm --network container:snip alpine:3 sh -c 'netstat -tln; wget -qO- http://127.0.0.1:3000/healthz'
```

```text
Active Internet connections (only servers)
Proto Recv-Q Send-Q Local Address           Foreign Address         State
tcp        0      0 0.0.0.0:3000            0.0.0.0:*               LISTEN
tcp        0      0 127.0.0.11:35963        0.0.0.0:*               LISTEN
{"status":"ok"}
```

The debug container sees exactly the sockets of `snip`, because it's in the same network namespace. Two sockets show
up: Node on port 3000, and the embedded DNS server's listener on `127.0.0.11` (Docker redirects port 53 to that random
port). Node listens on all addresses by default; on a host with IPv6 enabled the line reads `:::3000` instead (the
IPv6 "any" address, which accepts IPv4 too). Chapter 19 extends this
to sharing the process namespace too.

</details>

5. **Reach a host service.** Start a Node server on your machine (not in Docker) on port 4000, listening on all
   interfaces. Reach it from a container using a name that works both on Docker Desktop and on Linux. Then make the
   host server listen on `127.0.0.1` only and try again. What happens and why?

<details>
<summary>Solution</summary>

```bash
node -e 'require("node:http").createServer((q, s) => s.end("host\n")).listen(4000)' &
docker run --rm --add-host host.docker.internal:host-gateway alpine:3 wget -qO- http://host.docker.internal:4000
```

`--add-host …:host-gateway` is harmless on Docker Desktop (which already defines the name) and necessary on Linux, so
the same command works everywhere.

With the host server on `127.0.0.1` only, the request fails on Linux (connection refused): the container connects to
the host's bridge address (`172.17.0.1`), not to the host's loopback interface. Docker Desktop forwards
`host.docker.internal` traffic to your Mac's or PC's loopback interface, so it may still work there, one more
difference to be aware of when a setup works on one machine and not another.

</details>

---
← Previous: [Storage: volumes, bind mounts and tmpfs](08-storage-volumes-bind-mounts-and-tmpfs.md) | Next: [Docker Compose basics](10-docker-compose-basics.md) →
