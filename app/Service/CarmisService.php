<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Models\Carmis;

class CarmisService
{

    /**
     * 通过商品查询一些数量未使用的卡密
     *
     * @param int $goodsID 商品id
     * @param int $byAmount 数量
     * @return array|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function withGoodsByAmountAndStatusUnsold(int $goodsID, int $byAmount)
    {
        $carmis = Carmis::query()
            ->where('goods_id', $goodsID)
            ->where('status', Carmis::STATUS_UNSOLD)
            ->take($byAmount)
            ->get();
        return $carmis ? $carmis->toArray() : null;
    }

    /**
     * 通过id集合设置卡密已售出（带乐观锁）
     *
     * @param array $ids 卡密id集合
     * @return int 返回实际更新的行数
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function soldByIDS(array $ids): int
    {
        // ⭐ 乐观锁：只更新状态为未售出的卡密
        // 如果其他事务已经修改了status，UPDATE会影响0行
        $affected = Carmis::query()
            ->whereIn('id', $ids)
            ->where('status', Carmis::STATUS_UNSOLD)  // ⭐ 乐观锁检查
            ->where('is_loop', 0)
            ->update(['status' => Carmis::STATUS_SOLD]);

        // 记录日志
        \Log::info('卡密状态更新', [
            'ids' => $ids,
            'expected_count' => count($ids),
            'affected_rows' => $affected,
            'is_concurrent_conflict' => $affected != count($ids)
        ]);

        return $affected;
    }

}
