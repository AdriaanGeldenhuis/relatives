<?php
declare(strict_types=1);

function ht_expense_minor(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return (int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
}

function ht_expense_totals(array $expenses): array
{
    $groups = [];
    foreach ($expenses as $expense) {
        $code = $expense['currency'];
        $category = $expense['category'];
        if (!isset($groups[$code])) {
            $groups[$code] = ['currency' => $code, 'total_minor' => 0, 'count' => 0, 'categories' => []];
        }
        $group = &$groups[$code];
        $minor = ht_expense_minor((string) $expense['amount']);
        $group['total_minor'] += $minor;
        $group['count']++;
        $group['categories'][$category] = ($group['categories'][$category] ?? 0) + $minor;
        unset($group);
    }
    ksort($groups);
    return $groups;
}

function ht_expense_settlement(array $expenses, array $members, string $currency): array
{
    $shares = [];
    foreach ($members as $member) {
        $id = (int) $member['user_id'];
        $shares[$id] = ['id' => $id, 'name' => $member['name'], 'paid' => 0, 'share' => 0, 'balance' => 0];
    }
    $active = array_keys($shares);
    $total = 0;
    foreach ($expenses as $expense) {
        if ($expense['currency'] !== $currency) continue;
        $payer = (int) $expense['paid_by'];
        if (!isset($shares[$payer])) {
            $shares[$payer] = [
                'id' => $payer, 'name' => $expense['paid_by_name'] ?? 'Former member',
                'paid' => 0, 'share' => 0, 'balance' => 0,
            ];
        }
        $minor = ht_expense_minor((string) $expense['amount']);
        $total += $minor;
        $shares[$payer]['paid'] += $minor;

        $split = json_decode($expense['split_with_json'] ?? '', true);
        $ids = is_array($split) && $split ? array_map('intval', $split) : $active;
        $ids = array_values(array_unique([...$ids, $payer]));
        sort($ids, SORT_NUMERIC);
        $equal = intdiv($minor, count($ids));
        $remainder = $minor % count($ids);
        foreach ($ids as $index => $id) {
            $shares[$id] ??= ['id' => $id, 'name' => 'Former member', 'paid' => 0, 'share' => 0, 'balance' => 0];
            $shares[$id]['share'] += $equal + (int) ($index < $remainder);
        }
    }
    $debtors = $creditors = [];
    foreach ($shares as $id => &$member) {
        $balance = $member['paid'] - $member['share'];
        if ($balance < 0) $debtors[$id] = -$balance;
        if ($balance > 0) $creditors[$id] = $balance;
        $member['paid'] /= 100;
        $member['share'] /= 100;
        $member['balance'] = $balance / 100;
    }
    unset($member);
    arsort($debtors);
    arsort($creditors);
    $transfers = [];
    foreach ($debtors as $from => $owed) {
        foreach ($creditors as $to => &$due) {
            if ($due === 0) continue;
            $part = min($owed, $due);
            $transfers[] = ['from_id' => $from, 'from_name' => $shares[$from]['name'],
                'to_id' => $to, 'to_name' => $shares[$to]['name'], 'amount' => $part / 100];
            $owed -= $part;
            $due -= $part;
            if ($owed === 0) break;
        }
        unset($due);
    }
    return ['currency' => $currency, 'total' => $total / 100,
        'per_person' => $active ? round($total / count($active)) / 100 : 0,
        'member_count' => count($shares), 'members' => array_values($shares), 'settlements' => $transfers];
}
