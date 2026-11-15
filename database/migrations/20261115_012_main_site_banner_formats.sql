-- Explicitly mark the main-site top and footer placements as banner-capable.
UPDATE pf_ad_placements
SET formats_json=JSON_ARRAY('banner','native')
WHERE placement_key IN ('main_home_top','site_footer_banner');
