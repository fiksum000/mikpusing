-- MikroTik + FreeRADIUS example groups.
-- Import AFTER FreeRADIUS's standard MySQL schema.sql has been loaded into DB radius.
-- Values are examples: tune rates to your actual packages before using.
INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'PPPOE-10M', 'Mikrotik-Rate-Limit', ':=', '10M/10M'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='PPPOE-10M' AND attribute='Mikrotik-Rate-Limit');

INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'PPPOE-10M-FUP', 'Mikrotik-Rate-Limit', ':=', '2M/2M'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='PPPOE-10M-FUP' AND attribute='Mikrotik-Rate-Limit');

INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'ISOLIR', 'Mikrotik-Rate-Limit', ':=', '64k/64k'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='ISOLIR' AND attribute='Mikrotik-Rate-Limit');

INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'HOTSPOT-5M', 'Mikrotik-Rate-Limit', ':=', '5M/5M'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='HOTSPOT-5M' AND attribute='Mikrotik-Rate-Limit');

INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'HOTSPOT-10M', 'Mikrotik-Rate-Limit', ':=', '10M/10M'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='HOTSPOT-10M' AND attribute='Mikrotik-Rate-Limit');

-- While this group is assigned, RouterOS adds the subscriber's active address
-- to this firewall list. A dst-nat rule can then show /isolir/index.php for HTTP.
INSERT INTO radgroupreply (groupname, attribute, op, value)
SELECT 'ISOLIR', 'Mikrotik-Address-List', ':=', 'MIKPUSING-ISOLIR'
WHERE NOT EXISTS (SELECT 1 FROM radgroupreply WHERE groupname='ISOLIR' AND attribute='Mikrotik-Address-List');
