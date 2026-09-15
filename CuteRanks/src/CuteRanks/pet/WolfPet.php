<?php

declare(strict_types=1);

namespace CuteRanks\pet;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

final class WolfPet extends PetEntity{

    public static function getNetworkTypeId(): string{
        return EntityIds::WOLF;
    }
}