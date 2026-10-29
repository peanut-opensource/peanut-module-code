#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/install.php';
require __DIR__ . '/../vendor/autoload.php';

use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use think\facade\Db;

/** @return array{categories:list<string>,articles:list<array<string,mixed>>,tags:list<string>,members:list<array<string,mixed>>} */
function demoPlan(): array
{
    return [
        'categories' => ['产品介绍', '使用指南', '更新日志'],
        'articles' => [
            [
                'category' => '产品介绍',
                'title' => 'Peanut Module Code 产品简介',
                'desc' => '一套可直接演示的管理端、PC 与移动端应用基线。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>Peanut Module Code 提供管理端、PC 与移动端共用的内容和配置能力。</p><p>这些内容属于演示实例，可在文章管理中编辑或删除。</p>',
                'click_virtual' => 128,
                'sort' => 90,
            ],
            [
                'category' => '产品介绍',
                'title' => '从登录到内容管理',
                'desc' => '用一条清晰路径查看管理员登录、文章和分类。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>登录后打开内容管理，即可查看分类、文章、状态和分页操作。</p>',
                'click_virtual' => 96,
                'sort' => 80,
            ],
            [
                'category' => '使用指南',
                'title' => '管理员首次配置指南',
                'desc' => '从网站信息、菜单权限到基础素材的最小配置清单。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>建议先确认网站信息、管理员权限和素材中心，再开始录入业务内容。</p>',
                'click_virtual' => 84,
                'sort' => 70,
            ],
            [
                'category' => '使用指南',
                'title' => '文章发布演示',
                'desc' => '展示文章分类、摘要、正文和发布状态的完整流程。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>选择分类，填写标题和正文，保存后即可在 PC 与移动端内容入口查看。</p>',
                'click_virtual' => 72,
                'sort' => 60,
            ],
            [
                'category' => '更新日志',
                'title' => '新实例安装说明',
                'desc' => '说明空库首次安装与原目录更新的不同入口。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>新实例通过安装向导初始化空库；已有实例按当前版本的更新合同执行尚未应用的迁移，不重放首次安装。</p>',
                'click_virtual' => 64,
                'sort' => 50,
            ],
            [
                'category' => '更新日志',
                'title' => '演示数据说明',
                'desc' => '说明这些内容和会员均为可重复生成的合成演示数据。',
                'abstract' => 'Peanut Module Code Demo content',
                'content' => '<p>演示数据不代表真实客户、订单或支付记录，可以安全地用于产品体验。</p>',
                'click_virtual' => 48,
                'sort' => 40,
            ],
        ],
        'tags' => ['演示用户', '内容爱好者'],
        'members' => [
            ['sn' => 'DEMO0001', 'account' => 'demo_member_01', 'nickname' => '演示用户 01', 'channel' => 4, 'points' => 128, 'user_money' => '88.00'],
            ['sn' => 'DEMO0002', 'account' => 'demo_member_02', 'nickname' => '演示用户 02', 'channel' => 3, 'points' => 96, 'user_money' => '56.00'],
            ['sn' => 'DEMO0003', 'account' => 'demo_member_03', 'nickname' => '演示用户 03', 'channel' => 1, 'points' => 64, 'user_money' => '32.00'],
            ['sn' => 'DEMO0004', 'account' => 'demo_member_04', 'nickname' => '演示用户 04', 'channel' => 4, 'points' => 40, 'user_money' => '20.00'],
            ['sn' => 'DEMO0005', 'account' => 'demo_member_05', 'nickname' => '演示用户 05', 'channel' => 3, 'points' => 24, 'user_money' => '12.00'],
        ],
    ];
}

function demoUsage(): never
{
    fwrite(STDERR, "Usage: server/database/seed-demo-data.php --plan|--apply\n");
    exit(64);
}

function demoFail(string $message): never
{
    fwrite(STDERR, "Demo data seed failed: {$message}\n");
    exit(1);
}

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (count($arguments) !== 1 || !in_array($arguments[0], ['--plan', '--apply'], true)) {
    demoUsage();
}

$plan = demoPlan();
if ($arguments[0] === '--plan') {
    echo json_encode([
        'status' => 'planned',
        'categories' => count($plan['categories']),
        'articles' => count($plan['articles']),
        'tags' => count($plan['tags']),
        'members' => count($plan['members']),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

try {
    if (env('PEANUT_DEMO_MODE', false) !== 'enabled') {
        throw new RuntimeException('PEANUT_DEMO_MODE=enabled is required');
    }
    $input = installationProcessInput();
    $tenantId = filter_var($input['PEANUT_DEMO_TENANT_ID'] ?? env('PEANUT_DEMO_TENANT_ID', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tenantId === false) {
        throw new RuntimeException('PEANUT_DEMO_TENANT_ID must be a positive integer');
    }
    $docsUrl = trim(requiredEnvironment('PEANUT_DEMO_DOCS_URL'));
    if (filter_var($docsUrl, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($docsUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('PEANUT_DEMO_DOCS_URL must be an HTTP(S) URL');
    }

    $config = guardedDatabaseConfig();
    $app = ensureThinkPhpApplication(dirname(__DIR__));
    if (Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('id') === null) {
        throw new RuntimeException("active tenant {$tenantId} is not registered");
    }

    $now = time();
    $context = new TenantSystemContext($tenantId, 'demo-seed', 'demo.seed-business-data', 'demo-' . bin2hex(random_bytes(16)));
    $settingsQuery = $app->make(TenantSettingsQuery::class);
    $settingsCommands = $app->make(TenantSettingsCommands::class);
    $app->make(ExecutionContextStore::class)->run(new SystemExecutionContext($context), fn() => Db::transaction(function () use ($plan, $tenantId, $docsUrl, $now, $context, $settingsQuery, $settingsCommands): void {
        $website = $settingsQuery->get($context, 'website');
        if ($website->revision < 1) {
            throw new RuntimeException('the target tenant requires initialized website settings');
        }
        $categoryIds = [];
        foreach ($plan['categories'] as $sort => $name) {
            $category = Db::name('article_cate')->where('tenant_id', $tenantId)
                ->where('name', $name)->lock(true)->field('id')->find();
            $values = [
                'sort' => (count($plan['categories']) - $sort) * 10,
                'is_show' => 1,
                'delete_time' => null,
                'update_time' => $now,
            ];
            if ($category === null) {
                $categoryId = Db::name('article_cate')->insertGetId($values + [
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'create_time' => $now,
                ]);
            } else {
                $categoryId = (int) $category['id'];
                Db::name('article_cate')->where('tenant_id', $tenantId)->where('id', $categoryId)->update($values);
            }
            $categoryIds[$name] = (int) $categoryId;
        }

        foreach ($plan['articles'] as $article) {
            $articleId = Db::name('article')->where('tenant_id', $tenantId)
                ->where('title', $article['title'])->where('author', 'Peanut Module Code Demo')
                ->whereNull('delete_time')->lock(true)->value('id');
            $values = [
                'cid' => $categoryIds[$article['category']],
                'title' => $article['title'],
                'desc' => $article['desc'],
                'abstract' => $article['abstract'],
                'content' => $article['content'],
                'click_virtual' => $article['click_virtual'],
                'sort' => $article['sort'],
                'is_show' => 1,
                'update_time' => $now,
            ];
            if ($articleId === null) {
                Db::name('article')->insert($values + [
                    'tenant_id' => $tenantId,
                    'image' => '',
                    'author' => 'Peanut Module Code Demo',
                    'click_actual' => 0,
                    'create_time' => $now,
                ]);
            } else {
                Db::name('article')->where('tenant_id', $tenantId)->where('id', (int) $articleId)->update($values);
            }
        }

        $tagIds = [];
        foreach ($plan['tags'] as $name) {
            $tagId = Db::name('member_tag')->where('tenant_id', $tenantId)
                ->where('name', $name)->lock(true)->value('id');
            $values = [
                'remark' => 'Peanut Module Code Demo synthetic data',
                'delete_time' => null,
                'update_time' => $now,
            ];
            if ($tagId === null) {
                $tagId = Db::name('member_tag')->insertGetId($values + [
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'create_time' => $now,
                ]);
            } else {
                Db::name('member_tag')->where('tenant_id', $tenantId)->where('id', (int) $tagId)->update($values);
            }
            $tagIds[$name] = (int) $tagId;
        }

        foreach ($plan['members'] as $index => $memberData) {
            $memberId = Db::name('member')->where('tenant_id', $tenantId)
                ->where('sn', $memberData['sn'])->lock(true)->value('id');
            $values = [
                'nickname' => $memberData['nickname'],
                'channel' => $memberData['channel'],
                'user_money' => $memberData['user_money'],
                'points' => $memberData['points'],
                'status' => 1,
                'delete_time' => null,
                'update_time' => $now,
            ];
            if ($memberId === null) {
                $memberId = Db::name('member')->insertGetId($values + [
                    'tenant_id' => $tenantId,
                    'sn' => $memberData['sn'],
                    'account' => $memberData['account'],
                    'password' => '',
                    'avatar' => '',
                    'real_name' => '',
                    'mobile' => '',
                    'email' => '',
                    'sex' => 0,
                    'is_new_user' => 0,
                    'total_recharge_amount' => 0,
                    'create_time' => $now,
                ]);
            } else {
                Db::name('member')->where('tenant_id', $tenantId)->where('id', (int) $memberId)->update($values);
            }
            Db::name('member_tag_relation')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'member_id' => (int) $memberId,
                'tag_id' => $tagIds[$index % 2 === 0 ? '演示用户' : '内容爱好者'],
            ]);
        }

        $settingsCommands->replace($context, 'website', array_replace($website->document, ['official_url' => $docsUrl]));
    }));

    echo json_encode([
        'status' => 'applied', 'environment' => $config['environment'], 'resource_id' => $config['resource_id'],
        'tenant_id' => $tenantId, 'docs_url' => $docsUrl, 'categories' => count($plan['categories']),
        'articles' => count($plan['articles']), 'tags' => count($plan['tags']), 'members' => count($plan['members']),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $exception) {
    demoFail($exception->getMessage());
}
