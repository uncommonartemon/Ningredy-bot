<?php

namespace App\Services\Ai;

class DatabaseOperatorIntentDetector
{
    public function matches(?string $message): bool
    {
        $text = mb_strtolower(trim((string) $message));
        if (preg_match('/^(?:почему|зачем|как\b|что будет|можно ли|why\b|how\b|what happens)/u', $text)) {
            return false;
        }
        $action = preg_match('/(?:удал|очист|измен|помен|переимен|созда|добав|привя|назнач|перенес|свяж|покаж|перечисл|посчитай|сколько|видал|зміни|створ|додай|признач|\b(?:delete|remove|clear|update|change|rename|create|assign|attach|show|list|count)\b)/u', $text);
        $table = preg_match('/(?:таблиц|запис|строк|столб|колон|черновик|чернов|чернво|атрибут|характерист|групп|категори|бренд|товар|продукт|комплектац|вариант|источник|домен|\b(?:table|rows?|columns?|drafts?|attributes?|groups?|categories|brands|products?|variants?|sources?|domains?)\b)/u', $text);

        return $action === 1 && $table === 1;
    }
}
