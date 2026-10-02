<?php

declare(strict_types=1);

return [
    'zone_acc' => [
        'HEAD' => 'голову',
        'CHEST' => 'грудь',
        'BELLY' => 'живот',
        'LEGS' => 'ноги',
    ],
    'zone_label' => [
        'HEAD' => 'Голова',
        'CHEST' => 'Грудь',
        'BELLY' => 'Живот',
        'LEGS' => 'Ноги',
    ],
    'enemy_soldier' => 'Деревянный солдат',
    'enemy_wanderer' => 'Бродяга [:level]',
    'pierce' => ':attacker пробивает блок в :zone на :dmg',
    'block' => ':defender блокирует удар в :zone',
    'dodge' => ':defender уворачивается от удара в :zone',
    'hit' => ':attacker бьёт в :zone на :dmg',
    'no_potion_turn' => 'Нет зелий — ход без удара.',
    'drink_potion' => ':name пьёт зелье (+:heal HP)',
    'status' => "⚔️ :player ❤️:hp/:maxHp\n😈 :enemy ❤️:ehp/:emax",
    'pick_stance' => "\n\nВыбери стойку:",
    'pick_attack' => "\n\nКуда бьёшь?",
    'pick_defend' => "\n\nЧто блокируешь?",
    'potion_then_defend' => "\n\nЗелье вместо удара. Что блокируешь?",
    'win' => "\n\nПобеда! +:exp опыта, +:gold🪙",
    'lose' => "\n\nПоражение. Жди реген или купи зелье.",
    'btn_attack' => '⚔ Атака',
    'btn_defend' => '🛡 Защита',
    'btn_potion' => '🧪 Зелье (вместо удара)',
    'btn_soldier' => 'Деревянный солдат [0]',
    'btn_wanderer' => 'Бродяга [:level]',
    'pick_enemy' => 'Выбери противника:',
];
