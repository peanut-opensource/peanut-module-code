<?php

declare(strict_types=1);

use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Retire only byte-identical former Host examples; preserve every customized page and navigation entry. */
final class RemoveUnchangedHostExamples extends Migrator
{
    /** Whole original data/meta byte digests, not partial JSON or timestamp ownership guesses. */
    private const PAGES = [
        [1, '移动端首页', '94c2ec8c2c8a72b31633afb63732e5fc8db1f9b7f9a58a9fc0527bba64cbe939', 'e01b70236fa2b9732d3f1f12c0cec56ffd5ac06b0b92168bde017ab51b5bf601'],
        [2, '个人中心', 'f5bc78ed7796806ae45f20ac58cf84fb6e9f488e6d2b4af99ef9a9127177c7dd', '398257dd03a6da45c09cf80dcb78f9212ae638cb78999b9656e9dcbb17b40268'],
        [3, '客服设置', 'a0a74f59b84837c3b8c6408a597770fcec65ad312f4fc2fb52ffb8e61d39e2e9', '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'],
        [4, 'PC 首页', 'a46fee270e5e2849cf6a3093df3566032fa23981848c48dd8ec6f5baa9f5e9a9', '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'],
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if ($adapter->hasTable('pa_decorate_page')) {
            foreach (self::PAGES as [$type, $name, $dataDigest, $metaDigest]) {
                $this->execute(
                    "UPDATE pa_decorate_page SET data='[]', update_time=UNIX_TIMESTAMP()
                     WHERE type=? AND BINARY name=BINARY ?
                     AND SHA2(data,256)=? AND SHA2(meta,256)=?",
                    [$type, $name, $dataDigest, $metaDigest],
                );
            }
        }
        if ($adapter->hasTable('pa_decorate_tabbar')) {
            foreach ([
                [1, '资讯', '551ed5ffe7042071251e749cf934167d0df3ccb0b41384c84c584f3ddfb53b5e'],
                [2, '我的', 'b99123bbac05e8e53b005533d848a6953faed4d2d6d5cb3adb2155955618f453'],
            ] as [$position, $name, $linkDigest]) {
                $this->execute(
                    "DELETE FROM pa_decorate_tabbar
                     WHERE position=? AND BINARY name=BINARY ? AND SHA2(link,256)=?
                     AND is_show=1 AND OCTET_LENGTH(selected)=0 AND OCTET_LENGTH(unselected)=0",
                    [$position, $name, $linkDigest],
                );
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Retired examples are not reinserted. Restore the paired backup to recover their former bytes.');
    }
}
