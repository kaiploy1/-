<?php

declare(strict_types=1);

namespace CuteRanks\pet;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

abstract class PetEntity extends Living{

    private string $ownerName;
    private string $petDisplayName;
    private string $ability;
    private int $rarityAmplifier;
    private float $petScale;

    public function __construct(
        Location $location,
        string $ownerName,
        string $petDisplayName,
        string $ability,
        int $rarityAmplifier,
        float $scale,
        ?CompoundTag $nbt = null
    ){
        $this->ownerName = $ownerName;
        $this->petDisplayName = $petDisplayName;
        $this->ability = $ability;
        $this->rarityAmplifier = max(0, $rarityAmplifier);
        $this->petScale = max(0.3, min(2.0, $scale));

        parent::__construct($location, $nbt);

        $this->setNameTag($this->petDisplayName);
        $this->setNameTagVisible(true);
        $this->setNameTagAlwaysVisible(true);
        $this->setScale($this->petScale);
        $this->setCanSaveWithChunk(false);
        $this->setSilent();
        $this->setHasGravity(false);
        $this->setMotion(new Vector3(0.0, 0.0, 0.0));
    }

    protected function getInitialSizeInfo(): EntitySizeInfo{
        return new EntitySizeInfo(
            0.9,
            0.7
        );
    }

    public function getName(): string{
        return $this->petDisplayName;
    }

    public function getOwnerName(): string{
        return $this->ownerName;
    }

    public function getAbility(): string{
        return $this->ability;
    }

    public function getRarityAmplifier(): int{
        return $this->rarityAmplifier;
    }

    public function getPetScale(): float{
        return $this->petScale;
    }

    public function follow(Player $owner): void{
        if($this->isClosed() || !$owner->isConnected()){
            return;
        }

        $ownerLocation = $owner->getLocation();
        $direction = $owner->getDirectionVector();

        $targetX = $ownerLocation->x - ($direction->x * 1.7);
        $targetY = $ownerLocation->y + 0.25;
        $targetZ = $ownerLocation->z - ($direction->z * 1.7);

        $target = new Location(
            $targetX,
            $targetY,
            $targetZ,
            $owner->getWorld(),
            $ownerLocation->yaw,
            0.0
        );

        if($this->getWorld() !== $owner->getWorld()){
            $this->teleport($target);
            return;
        }

        $current = $this->getLocation();

        $differenceX = $targetX - $current->x;
        $differenceY = $targetY - $current->y;
        $differenceZ = $targetZ - $current->z;

        $distanceSquared =
            ($differenceX * $differenceX) +
            ($differenceY * $differenceY) +
            ($differenceZ * $differenceZ);

        /*
         * ไกลเกิน 8 บล็อก ให้วาร์ปกลับทันที
         */
        if($distanceSquared >= 64.0){
            $this->teleport($target);
            return;
        }

        /*
         * อยู่ใกล้แล้วไม่ต้องเคลื่อน
         */
        if($distanceSquared <= 0.16){
            $this->setMotion(
                new Vector3(0.0, 0.0, 0.0)
            );
            return;
        }

        /*
         * ใช้ Teleport แบบไล่ตำแหน่งแทน AI Mob
         * เพราะ PocketMine ไม่มี AI สัตว์ Vanilla สมบูรณ์ทุกชนิด
         */
        $movementMultiplier = 0.32;

        $nextLocation = new Location(
            $current->x +
                ($differenceX * $movementMultiplier),
            $current->y +
                ($differenceY * $movementMultiplier),
            $current->z +
                ($differenceZ * $movementMultiplier),
            $current->getWorld(),
            $ownerLocation->yaw,
            0.0
        );

        $this->teleport($nextLocation);
    }
}