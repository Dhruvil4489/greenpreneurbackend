-- SQL Patch to backfill ip_address from ip in brand_partner_clicks
-- Run manually on PostgreSQL; do not use Laravel migrations.

UPDATE brand_partner_clicks 
SET ip_address = ip 
WHERE ip_address IS NULL AND ip IS NOT NULL;
