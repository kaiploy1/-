<?php

declare(strict_types=1);

namespace CuteRanks\pet;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

final class CatPet extends PetEntity{

    public static function getNetworkTypeId(): string{
        return EntityIds::CAT;
    }
}