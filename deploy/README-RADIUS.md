# MikroTik RADIUS on Proxmox CT (FreeRADIUS + MariaDB)

This is an optional central authentication backend for PPPoE and Hotspot. Mikhmon still uses the MikroTik RouterOS API for router administration; RADIUS handles subscriber authentication, authorization and accounting.

## Supported baseline

- Ubuntu Server 22.04 LTS CT, a fixed LAN IP, and working DNS/internet.
- Start with one test router and a test user. Do not move every customer at once.
- 1,000 subscribers is a reasonable small-ISP target for a properly sized CT (suggested starting point: 2–4 vCPU, 4 GB RAM, SSD); actual capacity depends on accounting frequency, session churn and router count.
- Use UDP 1812 (authentication) and UDP 1813 (accounting). Permit those ports only from known MikroTik NAS IPs at both the CT and Proxmox firewalls.

## 1. Install RADIUS

On the CT (run as root), set RADIUS_NAS_IP to the source IP the router will use to reach RADIUS. Set RADIUS_SERVER_IP to the CT's stable IP.

~~~bash
apt update
apt install -y git
git clone https://github.com/fiksum000/mikpusing.git /var/www/mikhmon
cd /var/www/mikhmon
chmod +x deploy/install-freeradius.sh
RADIUS_NAS_IP=192.0.2.2 RADIUS_SERVER_IP=192.0.2.10 ./deploy/install-freeradius.sh
~~~

Replace the example addresses with your real LAN IPs. The installer generates a database password and a distinct RADIUS shared secret, uses the FreeRADIUS package's standard MySQL schema, creates a protected config at /etc/mikhmon/radius.php, and checks the daemon configuration before restarting the service. Credentials are stored in /root/mikpusing-radius-credentials.txt with mode 0600.

The script does **not** import local PPP secrets or Hotspot users automatically. It also does not turn off local authentication.

## 2. Add sample groups

Review the sample speed values, then import the policy groups:

~~~bash
mysql -u root radius < /var/www/mikhmon/db/radius-mikrotik-groups.example.sql
~~~

If MariaDB root uses socket authentication, run the command as root. The example groups are PPPOE-10M, PPPOE-10M-FUP, HOTSPOT-5M, HOTSPOT-10M and ISOLIR. Edit the rates and policy names before production. Add users through the Mikhmon **User RADIUS PPPoE/Hotspot** page at /admin.php?id=radius-users.

## 3. Configure MikroTik

Copy the exact values printed in /root/mikpusing-radius-credentials.txt and review each command before applying. A typical configuration is:

~~~routeros
/radius add address=<RADIUS_SERVER_IP> service=ppp,hotspot secret=<RADIUS_CLIENT_SECRET> authentication-port=1812 accounting-port=1813 timeout=1s
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m
~~~

The installer registers only RADIUS_NAS_IP as a client. If multiple routers or source IPs will authenticate, add each as a separately named client with its own shared secret. Verify that the router's RADIUS source IP matches the registered address.

**Keep a local break-glass router administrator and at least one local test account** until RADIUS works. Test one PPPoE account and one Hotspot account. Verify Access-Accept/Access-Reject and accounting updates on the CT before moving other users.

## 4. Accounting and monthly FUP

Enable Interim-Update on each MikroTik (the commands above request a 5-minute interval). After installing RADIUS, add a scheduled sampler:

~~~cron
*/5 * * * * www-data cd /var/www/mikhmon && /usr/bin/php cron/radius-accounting-snapshot.php >> storage/radius-accounting.log 2>&1
~~~

The sampler accumulates counter deltas from radacct into storage/radius_accounting.json, so a session's month-to-date traffic is not recounted from zero on each check. First use of a new subscriber/month starts with a baseline; traffic from before the first sample cannot be reconstructed. If interim accounting is missing, FUP automation skips users whose usage is unknown rather than applying a false quota decision.

The PPPoE package page maps FreeRADIUS group names for normal, FUP and ISOLIR states. Configure group names and rate policies to match. To preview profile changes:

~~~bash
cd /var/www/mikhmon
php cron/pppoe_profile_automation.php
~~~

The default is **dry-run**. To apply changes, run:

~~~bash
php cron/pppoe_profile_automation.php --apply
~~~

For RADIUS mode, changing a group affects new authentication. An already connected session may keep its prior policy until reauthentication. A separate Disconnect/CoA configuration is needed to disconnect active sessions safely; this installer deliberately does not enable remote disconnect automatically.

## Operations / security

- Keep /etc/mikhmon/radius.php and /root/mikpusing-radius-credentials.txt private.
- Do not open UDP 1812/1813 to the public Internet; restrict to router management/subscriber infrastructure.
- Keep SQL and RADIUS traffic on a trusted LAN/VLAN. Use a strong, unique secret for each NAS.
- Back up MariaDB RADIUS data and Mikhmon storage/ separately. These contain account credentials, customer data and accounting records.
- Validate systemctl status freeradius, journalctl -u freeradius, the Mikhmon log files and radacct before enabling bulk migration.
- Mikhmon's standard hotspot/voucher pages still administer local MikroTik objects through RouterOS API. Central RADIUS users should be managed with the RADIUS user page, and existing users must be migrated deliberately.
