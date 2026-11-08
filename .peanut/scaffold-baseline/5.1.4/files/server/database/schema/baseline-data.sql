INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, 'website',
       JSON_OBJECT(
         'name', 'Peanut Admin',
         'web_favicon', 'brand/favicon.svg',
         'web_logo', 'brand/logo.svg',
         'login_image', 'brand/login-background.svg',
         'shop_name', 'Peanut Admin',
         'shop_logo', 'brand/logo.svg',
         'pc_logo', 'brand/logo.svg',
         'pc_title', 'Peanut Admin',
         'pc_ico', 'brand/favicon.svg',
         'pc_desc', 'Peanut Admin 全端管理脚手架',
         'pc_keywords', 'Peanut Admin,Vue 3,ThinkPHP 8,UniApp',
         'h5_favicon', 'brand/favicon.svg',
         'slogan', '连接管理端、PC 与移动端的中性应用基线',
         'copyright', '花生科技',
         'official_url', '',
         'github_url', 'https://github.com/peanut-business/peanut-admin-code'
       ),
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @pa_default_tenant_id IS NOT NULL;

INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, 'copyright',
       JSON_OBJECT('config', JSON_ARRAY()),
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE @pa_default_tenant_id IS NOT NULL;

INSERT INTO `pa_tenant_setting`
  (`tenant_id`,`namespace`,`config_json`,`revision`,`create_time`,`update_time`)
SELECT @pa_default_tenant_id, seed.namespace, seed.config_json,
       1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
  SELECT 'agreement' AS namespace, JSON_OBJECT(
    'service_title', '', 'service_content', '',
    'privacy_title', '', 'privacy_content', ''
  ) AS config_json
  UNION ALL SELECT 'site-statistics', JSON_OBJECT('clarity_code', '')
  UNION ALL SELECT 'member-profile', JSON_OBJECT('user_avatar', 'brand/avatar-member.svg')
  UNION ALL SELECT 'login', JSON_OBJECT(
    'login_way', JSON_ARRAY(1, 2),
    'coerce_mobile', 0,
    'login_agreement', 0,
    'third_auth', 0,
    'wechat_auth', 0
  )
  UNION ALL SELECT 'web-page', JSON_OBJECT('status', 1, 'page_status', 0, 'page_url', '')
  UNION ALL SELECT 'hot-search', JSON_OBJECT('status', 0)
) seed
WHERE @pa_default_tenant_id IS NOT NULL;
