# Chapter 21 — Beyond a single host

## What you'll learn

- When one server stops being enough, and when it doesn't
- What an orchestrator adds: scheduling, desired state, self-healing, service discovery and rolling updates
- Docker Swarm mode: turning Docker Engines into a cluster, and deploying Snip as a stack
- Rolling updates with automatic rollback, secrets and configs in a cluster, and the pitfalls of state
- Kubernetes concepts mapped from what you already know in Compose
- Managed container platforms, and how to choose between all of these

## Do you need more than one server?

One server running Compose, as in chapter 18, handles a lot: thousands of requests per second for an app like Snip,
zero-downtime deploys, and restarts on failure. The reasons to go beyond it are specific:

| Reason | One server can't… | Alternatives that may suffice |
|---|---|---|
| **Availability** | Survive the server itself failing (hardware, kernel update, the provider's maintenance) | A standby server and a tested restore (chapter 18) may meet your target |
| **Capacity** | Grow beyond the largest machine you can rent | A bigger machine is often cheaper than a cluster's complexity |
| **Many services, many teams** | Give teams independent deployments and resource isolation | Separate servers per product |
| **Elasticity** | Scale up for a peak and back down | A managed platform (below) |

Running across several hosts changes three things fundamentally: something has to decide **where** each container
runs, containers on different hosts need a **network** between them, and **state** (volumes) no longer lives
where every container can reach it. An **orchestrator** handles the first two and forces you to think about the third.

## What an orchestrator does

You describe the **desired state**, "two API containers from image X, each with 256 MB, reachable as `api`", and
the orchestrator makes the cluster match it, continuously:

- **Scheduling**: it picks a node for each container, based on free resources and your constraints.
- **Reconciliation**: if a container dies or a node disappears, the actual state no longer matches, so it starts
  replacements elsewhere. You don't restart things; you change the description.
- **Service discovery and load balancing**: a stable name and address for a set of containers that come and go.
- **Rolling updates and rollbacks**: replacing containers a few at a time, checking health, undoing on failure.
- **Secrets and configuration**, distributed to the nodes that need them.

Chapter 18's `deploy.sh` did a small, single-host version of the rolling update by hand. An orchestrator does it as a
built-in feature, on any number of nodes.

## Docker Swarm mode

Swarm mode is built into Docker Engine. It reuses the Compose file format, so it's the shortest path from what you
know to a cluster. It's maintained and stable, but its ecosystem is small compared with Kubernetes; treat it as the
simple option, not the default for every team.

### A cluster

```bash
docker swarm init --advertise-addr <this-node-ip>          # on the first node: it becomes a manager
docker swarm join-token worker                             # prints a "docker swarm join …" command for workers
docker node ls
```

```text
ID                            HOSTNAME   STATUS    AVAILABILITY   MANAGER STATUS   ENGINE VERSION
e2h8gn392oslwp3y1ik4sywfe *   vm         Ready     Active         Leader           29.8.2
```

**Managers** store the cluster's state in a replicated log (Raft) and schedule work; **workers** only run containers.
Managers also run containers by default. For fault tolerance, run three or five managers: Raft needs a majority, so
three managers survive the loss of one, five survive two, and an even number adds nothing. Nodes talk on TCP 2377
(cluster management), TCP and UDP 7946 (node discovery) and UDP 4789 (overlay network traffic); open those between
the nodes and nowhere else.

### Services, tasks and stacks

A **service** is the desired state for one kind of container; each container it runs is a **task**. A **stack** is a
group of services deployed from one Compose-format file:

```bash
docker stack deploy -c stack.yaml snip       # create or update every service in the file
docker stack services snip
docker service ps snip_api                   # the tasks, including failed and replaced ones
docker service logs -f snip_api              # logs from every task, on every node
docker service scale snip_worker=3
```

### Snip as a stack

`examples/ch21-swarm/stack.yaml` is chapter 18's setup, translated. The interesting parts:

```yaml
x-snip: &snip
  image: ${SNIP_IMAGE:-registry.example.com/snip:1.10}
  # … environment, secrets, read_only, cap_drop as before …
  networks: [backend]

services:
  api:
    <<: *snip
    networks: [backend, edge]
    deploy:
      replicas: 2
      resources:
        limits: { cpus: "1", memory: 256M, pids: 100 }
      # Rolling updates: one container at a time, the new one started (and healthy) before
      # the old one stops, and an automatic rollback if the new version doesn't become healthy.
      update_config:
        parallelism: 1
        order: start-first
        failure_action: rollback
        monitor: 30s
      rollback_config:
        parallelism: 1
        order: start-first
      restart_policy:
        condition: any
        delay: 2s

  # A job: runs to completion once per deploy. The API starts at the same time and
  # retries its database connection until the schema exists.
  migrate:
    <<: *snip
    command: ["src/migrate.js"]
    deploy:
      mode: replicated-job
      restart_policy:
        condition: on-failure
        max_attempts: 10

  db:
    image: postgres:18-alpine
    volumes:
      - pgdata:/var/lib/postgresql
    deploy:
      replicas: 1
      # A local volume exists on one node only: pin the database to that node.
      placement:
        constraints: [node.labels.snip.db == true]

  nginx:
    image: nginx:1.31-alpine
    ports:
      - "${HTTP_PORT:-80}:80"
      - "${HTTPS_PORT:-443}:443"
    configs:
      - source: nginx_conf
        target: /etc/nginx/nginx.conf
    secrets:
      - source: tls_cert
        target: /etc/nginx/certs/fullchain.pem
      - source: tls_key
        target: /etc/nginx/certs/privkey.pem
    deploy:
      replicas: 2

networks:
  backend:
    driver: overlay
  edge:
    driver: overlay

secrets:
  db_password:
    file: ./secrets/db_password.txt
  # … tls_cert, tls_key …

configs:
  nginx_conf:
    # Configs and secrets can't be changed once created. To roll out a new nginx.conf,
    # bump the version in the name: the stack creates a new config and updates nginx.
    name: snip_nginx_conf_v1
    file: ./nginx/nginx.conf
```

What changed from Compose, and why:

- **No `build:`.** Every node must pull the image, so it has to be in a registry (chapter 15). A locally built image
  only exists on one node; `docker stack deploy` warns that it "could not be accessed on a registry".
- **No `depends_on`.** Swarm ignores it: in a cluster, any service can restart on any node at any time, so every
  service must cope with its dependencies being temporarily absent. Snip already retries its database connection
  (chapter 10), and the worker simply restarts until Valkey answers. The migration is a **job**
  (`mode: replicated-job`) that retries until it succeeds.
- **Overlay networks** span all nodes. Containers on different machines reach each other by service name, exactly as
  on one host (traffic between nodes can be encrypted: the `encrypted` driver option, as in `docker network create --driver
  overlay --opt encrypted`).
- **Secrets and configs** are stored, encrypted, in the managers' Raft log and delivered only to nodes running a task
  that uses them, as in-memory files under `/run/secrets/` (or any `target`). The application code from chapter 13
  works unchanged: it reads `DATABASE_PASSWORD_FILE`. Unlike Compose, Swarm secrets support `uid`, `gid` and `mode`.
- **State is pinned.** The `pgdata` volume is a local volume on whichever node first runs the database. If the
  database moved to another node, it would start with an empty volume. The placement constraint keeps it on the
  labeled node: `docker node update --label-add snip.db=true <node>`. A database that can move needs storage that
  follows it (a volume plugin backed by network storage) or, more commonly, a managed database outside the cluster.

### Load balancing: virtual IPs and the routing mesh

By default, each service gets a **virtual IP**: `api` resolves to one address, and the kernel's IPVS load balancer
spreads connections over the healthy tasks. A published port goes through the **routing mesh**: every node listens on
it and forwards to a task on any node, so an external load balancer can send traffic to all nodes without knowing
where nginx runs.

Swarm only adds a task to DNS and to the load balancer once its healthcheck passes. nginx's log during the first
deploy shows it waiting:

```text
[error] 20#20: api could not be resolved (3: Host not found)
…
{"time":"2026-10-15T13:37:20+00:00",…,"method":"POST","uri":"/api/links","status":201,…,"upstream":"10.0.1.4:3000",…}
```

> **Note on testing:** the machine used for this tutorial has no IPVS support in its kernel, so virtual IPs and the
> routing mesh couldn't work there (connections to a service's virtual IP failed with `EHOSTUNREACH`). The tests ran
> with `endpoint_mode: dnsrr` (DNS returns each task's address directly, as in Compose) and host-mode published ports
> (`{ target: 443, published: 8443, mode: host }`). On a normal Linux server, the defaults work.

### A rolling update, and an automatic rollback

```bash
docker service update --image registry.example.com/snip:1.10.1 snip_api
```

During the update, the same load generator as in chapter 18 (four clients over HTTPS, as fast as possible):

```text
snip_api.1 snip:1.10.1 Running  Running 30 seconds ago
snip_api.1 snip:1.10   Shutdown Shutdown 20 seconds ago
snip_api.2 snip:1.10.1 Running  Running 33 seconds ago
snip_api.2 snip:1.10   Shutdown Shutdown 29 seconds ago

{ 'POST 201': 24359, 'GET 302': 24359, 'POST 502': 1 }
```

One failed request out of 48,719 in this test, with DNS round robin (above), in which nginx briefly still knew a
stopping task's address. `order: start-first` is what keeps capacity during the update; the default,
`stop-first`, stops the old task before starting its replacement.

Then an image whose API can't start (chapter 18's `snip:broken`):

```bash
docker service update --image registry.example.com/snip:broken snip_api
```

```text
rollback: update rolled back due to failure or early termination of task k24d65mmyf1bz89k49fawhsz4
```

```bash
docker service inspect snip_api --format '{{.Spec.TaskTemplate.ContainerSpec.Image}} {{.UpdateStatus.State}}'
```

```text
snip:1.10.1 rollback_completed
```

The new task never became healthy, `failure_action: rollback` put the previous image back, and the other task kept
serving throughout. That's `deploy.sh` from chapter 18, without the script.

### Configs and secrets are immutable

Changing `nginx.conf` and running `docker stack deploy` again fails:

```text
failed to update config snip_nginx_conf: Error response from daemon: rpc error: code = InvalidArgument desc = only updates to Labels are allowed
```

A config or secret can't change after it's created, so services never see a file change underneath them. The way to
roll out a new version is a new name, which is what `name: snip_nginx_conf_v1` is for: bump it to `v2`, deploy, and
the stack creates the new config and rolls nginx over to it:

```text
Creating config snip_nginx_conf_v2
```

Remove old versions with `docker config rm` once nothing uses them. Secrets rotate the same way (chapter 13's
rotation, with a new secret name and a rolling update).

## Kubernetes

**Kubernetes** is the dominant orchestrator: every major cloud offers it as a managed service, and a large ecosystem
(Helm charts, operators, service meshes, autoscalers, GitOps tools) is built around it. It runs the same OCI images
your Dockerfiles produce, through containerd (chapter 20). What changes is how you describe the system: in
Kubernetes resources instead of a Compose file. Nearly every concept has a Compose counterpart:

| In Compose | In Kubernetes | Notes |
|---|---|---|
| A service's containers | **Pod** | One or more containers sharing a network namespace (like chapter 19's `--network container:`) |
| `deploy.replicas`, rolling updates | **Deployment** | Manages a ReplicaSet of identical pods; rolling update and rollback built in |
| Service name, DNS, load balancing | **Service** | A stable virtual IP and DNS name (`api.snip.svc.cluster.local`) for a set of pods |
| Published ports, nginx in front | **Ingress** or **Gateway** | HTTP routing and TLS at the cluster's edge, handled by an ingress or gateway controller |
| `environment:` | `env` in the pod, or a **ConfigMap** | |
| `secrets:` | **Secret** | Base64-encoded by default, not encrypted: enable encryption at rest, or use an external secret store |
| Named volume | **PersistentVolumeClaim** | Storage provisioned by the cluster (a cloud disk, for example) and reattached when the pod moves |
| A database service with a volume | **StatefulSet** | Stable names and storage per replica; most teams still prefer a managed database |
| `migrate` (a run-once job) | **Job** (and **CronJob** for schedules) | |
| `healthcheck:` | **Probes**: liveness, readiness, startup | Readiness decides traffic; liveness restarts; startup protects slow starts |
| `deploy.resources` | `resources.requests` and `limits` | Requests are for scheduling, limits are the cgroup limits (chapter 17) |
| `cap_drop`, `read_only`, `user` | `securityContext` | Same kernel features as chapter 16 |
| Compose project | **Namespace** | |
| `docker compose up` | `kubectl apply -f` | Desired state, reconciled continuously |

The API part of Snip as Kubernetes resources looks like this:

> **Just a taste — you don't need to learn or fully understand this now.** Kubernetes is a large topic of its own; this
> shows how much of this tutorial carries over. (Not tested against a cluster for this tutorial.)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: api
spec:
  replicas: 2
  selector:
    matchLabels: { app: snip-api }
  template:
    metadata:
      labels: { app: snip-api }
    spec:
      securityContext:
        runAsNonRoot: true
      containers:
        - name: api
          image: registry.example.com/snip:1.10
          ports:
            - containerPort: 3000
          env:
            - name: DATABASE_URL
              value: postgres://snip@db:5432/snip
            - name: DATABASE_PASSWORD_FILE
              value: /run/secrets/db_password
            - name: NODE_OPTIONS
              value: --max-old-space-size-percentage=60
          volumeMounts:
            - name: db-password
              mountPath: /run/secrets/db_password
              subPath: db_password
              readOnly: true
          readinessProbe:
            httpGet: { path: /healthz, port: 3000 }
            periodSeconds: 5
          resources:
            requests: { cpu: 250m, memory: 128Mi }
            limits: { memory: 256Mi }
          securityContext:
            readOnlyRootFilesystem: true
            allowPrivilegeEscalation: false
            capabilities: { drop: [ALL] }
      volumes:
        - name: db-password
          secret:
            secretName: snip-db
---
apiVersion: v1
kind: Service
metadata:
  name: api
spec:
  selector: { app: snip-api }
  ports:
    - port: 3000
      targetPort: 3000
```

Everything Snip needed to run well in Docker is exactly what it needs here: one process per container, configuration
from the environment, secrets from files, logs on stdout, a health endpoint, graceful shutdown on `SIGTERM`, a known
memory budget, and no writes outside volumes. That's the real payoff of the earlier chapters: the app is portable,
not just the image.

For local experiments, Docker Desktop includes a single-node Kubernetes cluster, and `kind` (Kubernetes in Docker)
runs clusters as containers. `kompose convert` translates a Compose file into Kubernetes resources as a starting
point.

## Managed container platforms

Between "one server" and "run your own cluster" there's a third option: hand the image to a platform that runs it.

| Kind | Examples | You provide | They handle |
|---|---|---|---|
| Serverless containers | Google Cloud Run, AWS App Runner, Azure Container Apps | An image, its port, environment, limits | Servers, scaling (including to zero), TLS, load balancing |
| Container services | AWS ECS (on Fargate or your own instances) | Task definitions (much like Compose services), networking | Scheduling, health, rolling deploys |
| App platforms | Fly.io, Render, Railway, DigitalOcean App Platform, Heroku | An image or a Dockerfile | Nearly everything, with less control |
| Managed Kubernetes | EKS, GKE, AKS, and others | Kubernetes resources | The control plane; you still run the workloads |

The same app properties apply everywhere, with one extra for scale-to-zero platforms: a fast start. Snip starts in
well under a second because the image is small and does no work at startup beyond connecting; a container that takes
30 seconds to start makes every scale-up and every cold start slow.

Managed databases (RDS, Cloud SQL, Azure Database, Neon, and others) pair naturally with all of these, and remove the
hardest part of running containers across hosts: the state.

## Choosing

```text
Is one server (plus backups and a tested restore) enough for your availability and capacity needs?
├─ yes → Compose on one server (chapter 18). Simplest to run, debug and afford.
└─ no
   ├─ Do you want to operate the platform yourself?
   │  ├─ no  → a managed platform: serverless containers or an app platform for a few services,
   │  │        managed Kubernetes when you have many services or need its ecosystem
   │  └─ yes → a few nodes and a small team: Swarm (Compose files, little to learn)
   │           many services, many teams, or ecosystem needs: Kubernetes
   └─ Either way: move the database to a managed service unless you have a reason not to.
```

Whatever you choose, the image and the app don't change. Moving between these options is a change of deployment
files, not of code, as long as the app follows the rules this tutorial built up.

## Key takeaways

- One well-run server goes a long way; go multi-host for availability, capacity or team independence, not by default.
- Orchestrators reconcile a desired state: they schedule containers, replace failed ones, give services stable names,
  and roll updates out and back.
- Swarm mode is built into Docker Engine and uses Compose files. Stacks need registry images, no `depends_on`
  (services must retry), overlay networks, pinned placement for local volumes, and versioned names for configs and
  secrets.
- `update_config` with `order: start-first` and `failure_action: rollback` gives rolling updates with automatic
  rollback; Swarm routes traffic only to healthy tasks.
- Kubernetes maps one-to-one onto concepts you know: pods, Deployments, Services, Ingress, ConfigMaps, Secrets,
  PersistentVolumeClaims, Jobs, probes, resources, security contexts.
- Managed platforms run your image for you; managed databases remove the hardest part of multi-host setups.
- An app that follows the earlier chapters' rules runs unchanged on all of them.

## Exercises

1. **A swarm of one.** On your machine, run `docker swarm init`, deploy `examples/ch21-swarm/stack.yaml` (create the
   secrets and the development certificate first, label the node, and build or tag a `snip` image), and use
   `docker service ps` to find every task the stack created, including the migration job. Then leave the swarm.

<details>
<summary>Solution</summary>

```bash
docker swarm init
cd examples/ch21-swarm
./create-secrets.sh && ./create-dev-cert.sh
docker node update --label-add snip.db=true "$(docker node ls -q)"
docker build -t snip:1.10 ../ch18-production
SNIP_IMAGE=snip:1.10 HTTP_PORT=8080 HTTPS_PORT=8443 docker stack deploy -c stack.yaml snip
docker stack services snip
docker stack ps snip --format '{{.Name}} {{.CurrentState}} {{.Error}}'
curl -sk https://localhost:8443/api/links -H 'content-type: application/json' -d '{"url":"https://example.com"}'
docker stack rm snip
docker swarm leave --force
```

`docker stack deploy` doesn't read `.env` files the way Compose does, so the variables are set in the shell. The
migration job may show a few failed tasks before one completes: it starts at the same time as the database and
retries until PostgreSQL accepts connections. The API tasks may also restart once or twice for the same reason, and
Swarm keeps them out of DNS until they're healthy. `docker stack rm` keeps the volumes; remove them with `docker
volume rm` if you're done.

</details>

2. **Kill a task.** With the stack running, kill one API container with `docker kill`. What does Swarm do, how long
   does it take, and what would happen to the same container under Compose with `restart: unless-stopped`?

<details>
<summary>Solution</summary>

```bash
docker kill "$(docker ps -q --filter name=snip_api | head -1)"
watch -n1 docker service ps snip_api
```

The task is marked `Failed` with exit 137, and Swarm schedules a *new* task (a new container, possibly on another
node) to get back to two replicas, after `restart_policy.delay` (2 s here). Compose's restart policy restarts the
*same* container on the same host. The difference matters when a node dies: Compose has nothing to restart it on,
Swarm reschedules every task from the lost node onto the remaining ones (except the database, which the placement
constraint pins to its labeled node).

</details>

3. **Translate a service.** Write the Kubernetes equivalent of Snip's `worker` service: a Deployment with two
   replicas, the same environment and secret file, resource limits, and the hardening from chapter 16. Which Compose
   setting has no direct counterpart, and why isn't one needed?

<details>
<summary>Solution</summary>

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: worker
spec:
  replicas: 2
  selector:
    matchLabels: { app: snip-worker }
  template:
    metadata:
      labels: { app: snip-worker }
    spec:
      securityContext:
        runAsNonRoot: true
      terminationGracePeriodSeconds: 10
      containers:
        - name: worker
          image: registry.example.com/snip:1.10
          args: ["src/worker.js"]
          env:
            - { name: DATABASE_URL, value: "postgres://snip@db:5432/snip" }
            - { name: DATABASE_PASSWORD_FILE, value: /run/secrets/db_password }
            - { name: VALKEY_URL, value: "redis://valkey:6379" }
            - { name: NODE_OPTIONS, value: "--max-old-space-size-percentage=60" }
          volumeMounts:
            - { name: db-password, mountPath: /run/secrets/db_password, subPath: db_password, readOnly: true }
          resources:
            requests: { cpu: 100m, memory: 64Mi }
            limits: { cpu: 500m, memory: 128Mi }
          securityContext:
            readOnlyRootFilesystem: true
            allowPrivilegeEscalation: false
            capabilities: { drop: [ALL] }
      volumes:
        - name: db-password
          secret: { secretName: snip-db }
```

`args` replaces the image's `CMD`, like Compose's `command` (Kubernetes' `command` would replace the `ENTRYPOINT`).
`healthcheck: disable: true` has no counterpart: Kubernetes ignores the image's `HEALTHCHECK` entirely and only runs
probes you define, so the worker simply has none. And there's no Service, because nothing connects *to* the worker.
`terminationGracePeriodSeconds` is Kubernetes' `stop_grace_period`.

</details>

4. **Choose for a scenario.** For each case, pick a deployment option from this chapter and give two reasons:
   (a) an internal tool used by 20 people, with a PostgreSQL database; (b) an API with traffic spikes of 50× during a
   daily event and nearly nothing at night; (c) 40 services owned by 8 teams, each deploying several times a day.

<details>
<summary>Solution</summary>

- (a) **Compose on one server** (chapter 18), with nightly backups copied off the server and a tested restore. The
  load is trivial, brief downtime during a server failure is acceptable for an internal tool, and one person can
  understand and operate the whole setup.
- (b) **Serverless containers** (Cloud Run, Container Apps, App Runner) with a managed database. They scale with
  requests, including down to zero at night, so you pay for the spikes and not for idle capacity; the app needs a
  fast start and a database connection limit that survives many instances (a connection pooler).
- (c) **Managed Kubernetes**. Namespaces, quotas and access control separate the teams; the ecosystem (GitOps,
  autoscaling, policy) supports many independent deployments per day; and the managed control plane removes the
  hardest part of operating it.

There's no single right answer for any of them, but the reasons should be about availability, scale, team structure
and operating effort, not about which tool is newest.

</details>

---
← Previous: [How Docker works inside](20-how-docker-works-inside.md) | Next: [Capstone project: Snip](22-capstone-project.md) →
