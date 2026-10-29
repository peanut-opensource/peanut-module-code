INSERT INTO `pa_system_dict_type` (`code`, `name`, `remark`)
VALUES
  ('member_sex', '会员性别', '系统固定枚举，租户不可修改'),
  ('member_status', '会员状态', '系统固定枚举，租户不可修改'),
  ('member_channel', '会员注册渠道', '系统固定枚举，租户不可修改'),
  ('payment_status', '支付状态', '系统固定枚举，租户不可修改'),
  ('refund_status', '退款状态', '系统固定枚举，租户不可修改');

INSERT INTO `pa_system_dict_data` (`type_code`, `name`, `value`, `sort`, `remark`)
VALUES
  ('member_sex', '未知', '0', 30, 'unknown'),
  ('member_sex', '男', '1', 20, 'male'),
  ('member_sex', '女', '2', 10, 'female'),
  ('member_status', '正常', '1', 20, 'active'),
  ('member_status', '禁用', '0', 10, 'disabled'),
  ('member_channel', '微信小程序', '1', 60, 'wechat_mmp'),
  ('member_channel', '微信公众号', '2', 50, 'wechat_oa'),
  ('member_channel', 'H5', '3', 40, 'h5'),
  ('member_channel', 'PC', '4', 30, 'pc'),
  ('member_channel', 'iOS', '5', 20, 'ios'),
  ('member_channel', 'Android', '6', 10, 'android'),
  ('payment_status', '待支付', '0', 20, 'unpaid'),
  ('payment_status', '已支付', '1', 10, 'paid'),
  ('refund_status', '处理中', '0', 30, 'processing'),
  ('refund_status', '成功', '1', 20, 'success'),
  ('refund_status', '失败', '2', 10, 'failed');

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

INSERT INTO `pa_crontab`
  (`name`,`type`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`sort`,`remark`,`create_time`,`update_time`,`tenant_id`)
SELECT '代码生成归档清理',1,'generator:cleanup','',1,'0 3 * * *','',0,0,0,20,
       '清理已使用或过期的代码生成下载令牌和隔离归档',0,0,@pa_default_tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `pa_crontab` WHERE `command`='generator:cleanup');

INSERT INTO `pa_storage_account` (`account_key`,`driver`,`name`,`credential_ciphertext`,`credential_key_version`,`credential_rotated_at`,`status`,`created_at`,`updated_at`) VALUES ('local','local','本地存储',NULL,NULL,NULL,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));

INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-public',`id`,'本地公开文件','public','public/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';

INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-private',`id`,'本地私有文件','private','private/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';

INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`)
 SELECT 'default.public','public',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-public';

INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`) SELECT 'default.private','private',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-private';
