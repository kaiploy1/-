<?php

declare(strict_types=1);

namespace CuteRanks;

use CuteRanks\chat\RankChatFormatter;
use CuteRanks\form\CustomForm;
use CuteRanks\form\SimpleForm;
use CuteRanks\pet\BeePet;
use CuteRanks\pet\CatPet;
use CuteRanks\pet\FoxPet;
use CuteRanks\pet\PetEntity;
use CuteRanks\pet\RabbitPet;
use CuteRanks\pet\WolfPet;
use MyServer\SimpleMoney\Main as SimpleMoneyMain;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\data\bedrock\item\enchantment\StringToEnchantmentParser;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Durable;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\permission\PermissionAttachment;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use Throwable;

final class Main extends PluginBase implements Listener{

    private static Main $instance;

    private Config $playerData;
    private SimpleMoneyMain $simpleMoney;

    /** @var array<string, PermissionAttachment> */
    private array $attachments = [];

    /** @var array<string, PetEntity> */
    private array $activePets = [];

    private int $petTicks = 0;

    protected function onEnable(): void{
        self::$instance = $this;

        $this->saveDefaultConfig();

        $moneyPlugin = $this->getServer()
            ->getPluginManager()
            ->getPlugin("SimpleMoney");

        if(!$moneyPlugin instanceof SimpleMoneyMain){
            $this->getLogger()->error(
                "ไม่พบ SimpleMoney หรือ Main class ไม่ตรงกับ MyServer\\SimpleMoney\\Main"
            );

            $this->getServer()
                ->getPluginManager()
                ->disablePlugin($this);

            return;
        }

        $this->simpleMoney = $moneyPlugin;

        $this->playerData = new Config(
            $this->getDataFolder() . "players.yml",
            Config::YAML,
            ["players" => []]
        );

        // ลงทะเบียน Custom Pet Entity ก่อนอนุญาตให้มีการสร้าง Pet
        $this->registerPetEntities();

        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);

        $rainbowTicks = max(
            1,
            (int) $this->getConfig()->getNested(
                "settings.rainbow-update-ticks",
                10
            )
        );

        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function(): void{
                $this->updateAnimatedNameTags();
            }),
            $rainbowTicks
        );

        $petUpdateTicks = max(
            1,
            (int) $this->getConfig()->getNested(
                "pets.follow-update-ticks",
                10
            )
        );

        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() use ($petUpdateTicks): void{
                $this->petTicks += $petUpdateTicks;
                $this->updatePets();
            }),
            $petUpdateTicks
        );

        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->preparePlayer($player);
        }

        $this->getLogger()->info(
            "CuteRanks v2 เปิดใช้งานเรียบร้อยแล้ว"
        );
    }

    protected function onDisable(): void{
        if(isset($this->playerData)){
            $this->playerData->save();
        }

        foreach($this->activePets as $pet){
            if(!$pet->isClosed()){
                $pet->flagForDespawn();
            }
        }

        $this->activePets = [];

        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->removeAttachment($player);
        }
    }

    public static function getInstance(): Main{
        return self::$instance;
    }

    public function onJoin(PlayerJoinEvent $event): void{
        $this->preparePlayer($event->getPlayer());
    }

    public function onQuit(PlayerQuitEvent $event): void{
        $player = $event->getPlayer();

        $this->removeAttachment($player);
        $this->removeActivePet($player->getName());
    }

    public function onPetDamage(EntityDamageEvent $event): void{
        if($event->getEntity() instanceof PetEntity){
            $event->cancel();
        }
    }

    public function onChat(PlayerChatEvent $event): void{
        $player = $event->getPlayer();
        $phase = $this->rainbowPhase();

        $event->setFormatter(
            new RankChatFormatter(
                $this->renderRankPrefix($player->getName(), $phase),
                $this->getNickname($player->getName()),
                $this->translateColors(
                    (string) $this->getConfig()->getNested(
                        "settings.chat-separator",
                        " §d♡ §f"
                    )
                ),
                $this->getSelectedStyle($player->getName()),
                $this->getColorStyles(),
                $phase
            )
        );
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args
    ): bool{
        $commandName = $this->normalizeCommand(
            $command->getName()
        );

        if($commandName === "rank"){
            return $this->handleRankCommand($sender, $args);
        }

        if($commandName === "rankcmd"){
            if(!$sender instanceof Player){
                $sender->sendMessage(
                    "คำสั่งนี้ใช้ได้เฉพาะผู้เล่นในเกม"
                );
                return true;
            }

            $this->showRankCommands($sender);
            return true;
        }

        if($commandName === "rankrename"){
            if(!$sender instanceof Player){
                $sender->sendMessage(
                    "คำสั่งนี้ใช้ได้เฉพาะผู้เล่นในเกม"
                );
                return true;
            }

            $this->showRankRenameForm($sender);
            return true;
        }

        if($commandName === "pets"){
            if(!$sender instanceof Player){
                $sender->sendMessage(
                    "คำสั่งนี้ใช้ได้เฉพาะผู้เล่นในเกม"
                );
                return true;
            }

            if(
                isset($args[0]) &&
                strtolower((string) $args[0]) === "admin" &&
                $this->canManageRanks($sender)
            ){
                $this->showPetAdminForm($sender);
                return true;
            }

            if(!$this->canUseRankCommand($sender, "pets")){
                $sender->sendMessage(
                    $this->prefix() .
                    "§cยศของคุณยังเปิดระบบสัตว์เลี้ยงไม่ได้"
                );
                return true;
            }

            $this->showPetMenu($sender);
            return true;
        }

        if(!$sender instanceof Player){
            return $this->handleConsoleCommand(
                $sender,
                $commandName,
                $args
            );
        }

        if(!$this->canUseRankCommand($sender, $commandName)){
            $sender->sendMessage(
                $this->prefix() .
                "§cยศของคุณยังใช้คำสั่ง §f/" .
                $commandName .
                " §cไม่ได้"
            );
            return true;
        }

        return $this->executeRankCommand(
            $sender,
            $commandName,
            $args
        );
    }

    private function handleRankCommand(
        CommandSender $sender,
        array $args
    ): bool{
        if(isset($args[0])){
            $subCommand = strtolower((string) $args[0]);

            if($subCommand === "set"){
                if(!$this->canManageRanks($sender)){
                    $sender->sendMessage(
                        $this->prefix() . "§cคุณไม่มีสิทธิ์กำหนดยศ"
                    );
                    return true;
                }

                if(!isset($args[1], $args[2])){
                    $sender->sendMessage(
                        "§eใช้: /rank set <ผู้เล่น> <ยศ>"
                    );
                    return true;
                }

                $playerName = trim((string) $args[1]);
                $rankId = strtolower(trim((string) $args[2]));

                if(!$this->setRank($playerName, $rankId)){
                    $sender->sendMessage(
                        $this->prefix() .
                        "§cไม่พบยศ §f{$rankId}"
                    );
                    return true;
                }

                $sender->sendMessage(
                    $this->prefix() .
                    "§aเปลี่ยนยศของ §f{$playerName} " .
                    "§aเป็น §d" .
                    $this->getRankDisplay($rankId) .
                    " §aแล้ว"
                );

                return true;
            }

            if($subCommand === "reload"){
                if(!$this->canManageRanks($sender)){
                    $sender->sendMessage(
                        $this->prefix() . "§cคุณไม่มีสิทธิ์ Reload"
                    );
                    return true;
                }

                $this->reloadConfig();

                foreach(
                    $this->getServer()->getOnlinePlayers()
                    as $online
                ){
                    $this->applyRankPermissions($online);
                    $this->updateNameTag($online);
                    $this->spawnEquippedPet($online);
                }

                $sender->sendMessage(
                    $this->prefix() .
                    "§aโหลด config.yml ใหม่เรียบร้อยแล้ว"
                );

                return true;
            }
        }

        if(!$sender instanceof Player){
            $sender->sendMessage(
                "§e/rank set <ผู้เล่น> <ยศ>"
            );
            $sender->sendMessage(
                "§e/rank reload"
            );
            return true;
        }

        if($this->canManageRanks($sender)){
            $this->showAdminMenu($sender);
        }else{
            $this->showPlayerRankMenu($sender);
        }

        return true;
    }

    private function handleConsoleCommand(
        CommandSender $sender,
        string $commandName,
        array $args
    ): bool{
        if($commandName === "kick"){
            if(!isset($args[0])){
                $sender->sendMessage(
                    "§eใช้: /kick <ผู้เล่น> [เหตุผล]"
                );
                return true;
            }

            $target = $this->findOnlinePlayer(
                (string) $args[0]
            );

            if($target === null){
                $sender->sendMessage("§cไม่พบผู้เล่น");
                return true;
            }

            $reason = count($args) > 1
                ? implode(" ", array_slice($args, 1))
                : "ถูกนำออกโดย Console";

            $target->kick(
                "§cคุณถูกนำออกจากเซิร์ฟเวอร์\n" .
                "§fเหตุผล: §e{$reason}"
            );

            return true;
        }

        $sender->sendMessage(
            "คำสั่งนี้ใช้ได้เฉพาะผู้เล่นในเกม"
        );

        return true;
    }

    private function executeRankCommand(
        Player $player,
        string $commandName,
        array $args
    ): bool{
        return match($commandName){
            "fly" => $this->executeFly($player),
            "tp" => $this->executeTeleport($player, $args),
            "fix" => $this->executeFix($player),
            "heal" => $this->executeHeal($player),
            "enchant" => $this->executeEnchant($player, $args),
            "nick" => $this->executeNickname($player, $args),
            "size" => $this->executeSize($player, $args),
            "time" => $this->executeTime($player, $args),
            "kick" => $this->executeRankKick($player, $args),
            "readmin" => $this->executeAdminRequest($player, $args),
            default => true
        };
    }

    private function executeFly(Player $player): bool{
        $enabled = !$player->getAllowFlight();

        $player->setAllowFlight($enabled);

        if(!$enabled){
            $player->setFlying(false);
        }

        $player->sendMessage(
            $this->prefix() .
            (
                $enabled
                    ? "§aเปิดการบินแล้ว §d♡"
                    : "§cปิดการบินแล้ว §f♡"
            )
        );

        return true;
    }

    private function executeTeleport(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0])){
            $player->sendMessage(
                "§eใช้: /tp <ชื่อผู้เล่น>"
            );
            return true;
        }

        $target = $this->findOnlinePlayer(
            (string) $args[0]
        );

        if($target === null){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบผู้เล่นที่ต้องการวาร์ป"
            );
            return true;
        }

        $player->teleport($target->getLocation());

        $player->sendMessage(
            $this->prefix() .
            "§aวาร์ปไปหา §f{$target->getName()} §aแล้ว"
        );

        return true;
    }

    private function executeFix(Player $player): bool{
        $item = $player->getInventory()->getItemInHand();

        if(!$item instanceof Durable){
            $player->sendMessage(
                $this->prefix() .
                "§cไอเทมที่ถืออยู่ไม่สามารถซ่อมได้"
            );
            return true;
        }

        $item->setDamage(0);
        $player->getInventory()->setItemInHand($item);

        $player->sendMessage(
            $this->prefix() .
            "§aซ่อมไอเทมเรียบร้อยแล้ว §d♡"
        );

        return true;
    }

    private function executeHeal(Player $player): bool{
        $player->setHealth($player->getMaxHealth());
        $player->getHungerManager()->setFood(20);
        $player->getHungerManager()->setSaturation(20.0);

        $player->sendMessage(
            $this->prefix() .
            "§aเติมเลือดและความหิวเรียบร้อยแล้ว §d♡"
        );

        return true;
    }

    private function executeEnchant(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0])){
            $player->sendMessage(
                "§eใช้: /enchant <ชื่อมนตร์> [เลเวล]"
            );
            return true;
        }

        $enchantmentName = strtolower(
            trim((string) $args[0])
        );

        $level = isset($args[1])
            ? max(1, min(255, (int) $args[1]))
            : 1;

        $enchantment = StringToEnchantmentParser::getInstance()
            ->parse($enchantmentName);

        if($enchantment === null){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบมนตร์ §f{$enchantmentName}"
            );
            return true;
        }

        $item = $player->getInventory()->getItemInHand();

        if($item->isNull()){
            $player->sendMessage(
                $this->prefix() .
                "§cกรุณาถือไอเทมก่อนใช้คำสั่ง"
            );
            return true;
        }

        try{
            $item->addEnchantment(
                new EnchantmentInstance(
                    $enchantment,
                    $level
                )
            );

            $player->getInventory()->setItemInHand($item);

            $player->sendMessage(
                $this->prefix() .
                "§aเพิ่มมนตร์ §f{$enchantmentName} " .
                "§aเลเวล §f{$level} §aแล้ว"
            );
        }catch(Throwable){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่สามารถเพิ่มมนตร์ให้ไอเทมนี้ได้"
            );
        }

        return true;
    }

    private function executeNickname(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0])){
            $player->sendMessage(
                "§eใช้: /nick <ชื่อใหม่|off>"
            );
            return true;
        }

        $input = trim(implode(" ", $args));

        if(in_array(
            strtolower($input),
            ["off", "reset", "clear"],
            true
        )){
            $this->setPlayerDataValue(
                $player->getName(),
                "nickname",
                $player->getName()
            );

            $this->updateNameTag($player);

            $player->sendMessage(
                $this->prefix() .
                "§aรีเซ็ตชื่อเล่นเรียบร้อยแล้ว"
            );

            return true;
        }

        $cleanName = TextFormat::clean(
            $this->translateColors($input)
        );

        $maximumLength = max(
            1,
            (int) $this->getConfig()->getNested(
                "settings.maximum-nickname-length",
                16
            )
        );

        if(
            $cleanName === "" ||
            mb_strlen($cleanName) > $maximumLength
        ){
            $player->sendMessage(
                $this->prefix() .
                "§cชื่อเล่นต้องไม่เกิน §f" .
                $maximumLength .
                " §cตัวอักษร"
            );

            return true;
        }

        $this->setPlayerDataValue(
            $player->getName(),
            "nickname",
            $cleanName
        );

        $this->updateNameTag($player);

        $player->sendMessage(
            $this->prefix() .
            "§aเปลี่ยนชื่อเล่นเป็น §d{$cleanName} §aแล้ว"
        );

        return true;
    }

    private function executeSize(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0]) || !is_numeric($args[0])){
            $player->sendMessage(
                "§eใช้: /size <ขนาด>"
            );
            return true;
        }

        $minimum = (float) $this->getConfig()->getNested(
            "settings.minimum-size",
            0.3
        );

        $maximum = (float) $this->getConfig()->getNested(
            "settings.maximum-size",
            3.0
        );

        $size = max(
            $minimum,
            min($maximum, (float) $args[0])
        );

        $player->setScale($size);

        $player->sendMessage(
            $this->prefix() .
            "§aเปลี่ยนขนาดเป็น §f{$size} §aแล้ว"
        );

        return true;
    }

    private function executeTime(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0])){
            $player->sendMessage(
                "§eใช้: /time <day|night|sunrise|sunset|ตัวเลข>"
            );
            return true;
        }

        $input = strtolower((string) $args[0]);

        $time = match($input){
            "day" => 1000,
            "sunrise" => 23000,
            "sunset" => 12000,
            "night" => 13000,
            default => is_numeric($input)
                ? max(0, (int) $input)
                : -1
        };

        if($time < 0){
            $player->sendMessage(
                $this->prefix() .
                "§cรูปแบบเวลาไม่ถูกต้อง"
            );
            return true;
        }

        $player->getWorld()->setTime($time);

        $player->sendMessage(
            $this->prefix() .
            "§aเปลี่ยนเวลาเรียบร้อยแล้ว"
        );

        return true;
    }

    private function executeRankKick(
        Player $player,
        array $args
    ): bool{
        if(!isset($args[0])){
            $player->sendMessage(
                "§eใช้: /kick <ผู้เล่น> [เหตุผล]"
            );
            return true;
        }

        $target = $this->findOnlinePlayer(
            (string) $args[0]
        );

        if($target === null){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบผู้เล่นที่ต้องการนำออก"
            );
            return true;
        }

        if($target === $player){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่สามารถนำตัวเองออกได้"
            );
            return true;
        }

        if(
            !$this->isAdministratorRank($player->getName()) &&
            $this->getRankLevel($target->getName()) >=
            $this->getRankLevel($player->getName())
        ){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่สามารถนำผู้เล่นยศเท่ากันหรือสูงกว่าออกได้"
            );

            return true;
        }

        if(
            !$this->isAdministratorRank($player->getName()) &&
            !$this->consumeKickUsage($player)
        ){
            $player->sendMessage(
                $this->prefix() .
                "§cใช้สิทธิ์ Kick ครบ 2 ครั้งในช่วง 3 วันแล้ว"
            );

            return true;
        }

        $reason = count($args) > 1
            ? implode(" ", array_slice($args, 1))
            : "ถูกนำออกโดยผู้เล่นยศพิเศษ";

        $target->kick(
            "§cคุณถูกนำออกจากเซิร์ฟเวอร์\n" .
            "§fโดย: §d{$player->getName()}\n" .
            "§fเหตุผล: §e{$reason}"
        );

        $player->sendMessage(
            $this->prefix() .
            "§aได้นำ §f{$target->getName()} §aออกแล้ว"
        );

        return true;
    }

    private function executeAdminRequest(
        Player $player,
        array $args
    ): bool{
        $reason = count($args) > 0
            ? implode(" ", $args)
            : "ต้องการความช่วยเหลือจากแอดมิน";

        $adminCount = 0;

        foreach($this->getServer()->getOnlinePlayers() as $online){
            if(!$this->isAdministratorRank($online->getName())){
                continue;
            }

            $online->sendMessage(
                "§l§d♡ §cADMIN REQUEST §d♡\n" .
                "§fผู้เล่น: §b{$player->getName()}\n" .
                "§fข้อความ: §e{$reason}\n" .
                "§fโลก: §d" .
                $player->getWorld()->getFolderName()
            );

            $adminCount++;
        }

        $player->sendMessage(
            $this->prefix() .
            (
                $adminCount > 0
                    ? "§aแจ้งแอดมินแล้ว §f{$adminCount} §aคน"
                    : "§eขณะนี้ยังไม่มีแอดมินออนไลน์"
            )
        );

        return true;
    }

    private function showPlayerRankMenu(Player $player): void{
        $rankId = $this->getPlayerRank($player->getName());

        $actions = [];

        $form = new SimpleForm(
            function(Player $player, int $selected) use (&$actions): void{
                if(isset($actions[$selected])){
                    $actions[$selected]($player);
                }
            }
        );

        $form->setTitle("§l§d♡ §bระบบยศ §d♡");

        $form->setContent(
            "§fผู้เล่น: §b{$player->getName()}\n" .
            "§fชื่อแสดงผล: §d" .
            $this->getNickname($player->getName()) .
            "\n§fยศปัจจุบัน: " .
            $this->renderRankPrefix(
                $player->getName(),
                $this->rainbowPhase()
            ) .
            "\n§fชื่อยศหลัก: §e" .
            $this->getRankDisplay($rankId) .
            "\n§fสีปัจจุบัน: " .
            $this->getStyleDisplayName(
                $this->getSelectedStyle($player->getName())
            )
        );

        $form->addButton(
            "§l§dเลือกสี\n§r§fเปลี่ยนสีชื่อและแชต"
        );
        $actions[] = fn(Player $target) =>
            $this->showColorForm($target);

        $form->addButton(
            "§l§bคำสั่งของฉัน\n§r§fดูคำสั่งที่ยศนี้ใช้ได้"
        );
        $actions[] = fn(Player $target) =>
            $this->showRankCommands($target);

        if($this->canUseRankCommand($player, "pets")){
            $form->addButton(
                "§l§eสัตว์เลี้ยง\n§r§fซื้อและเลือกสัตว์คู่ใจ"
            );
            $actions[] = fn(Player $target) =>
                $this->showPetMenu($target);
        }

        if($this->canUseRankCommand($player, "rankrename")){
            $form->addButton(
                "§l§6เปลี่ยนชื่อยศ\n§r§fใช้ได้ทุก 30 วัน"
            );
            $actions[] = fn(Player $target) =>
                $this->showRankRenameForm($target);
        }

        $player->sendForm($form);
    }

    private function showAdminMenu(Player $player): void{
        $actions = [];

        $form = new SimpleForm(
            function(Player $player, int $selected) use (&$actions): void{
                if(isset($actions[$selected])){
                    $actions[$selected]($player);
                }
            }
        );

        $form->setTitle("§l§c♡ §dCuteRanks Admin §c♡");
        $form->setContent(
            "§fจัดการยศ คำสั่ง สี และสัตว์เลี้ยง"
        );

        $form->addButton(
            "§l§aกำหนดยศผู้เล่น\n§r§fเลือกผ่าน Dropdown"
        );
        $actions[] = fn(Player $target) =>
            $this->showAssignRankForm($target);

        $form->addButton(
            "§l§eแก้คำสั่งประจำยศ\n§r§fเพิ่มหรือลบคำสั่ง"
        );
        $actions[] = fn(Player $target) =>
            $this->showRankCommandPicker($target);

        $form->addButton(
            "§l§dเลือกสีของฉัน\n§r§fแอดมินใช้ Rainbow ได้"
        );
        $actions[] = fn(Player $target) =>
            $this->showColorForm($target);

        $form->addButton(
            "§l§6แจกสัตว์เลี้ยง\n§r§fเลือกชนิดและระดับได้"
        );
        $actions[] = fn(Player $target) =>
            $this->showPetAdminForm($target);

        $form->addButton(
            "§l§bสัตว์เลี้ยงของฉัน\n§r§fเปิดหน้าระบบสัตว์เลี้ยง"
        );
        $actions[] = fn(Player $target) =>
            $this->showPetMenu($target);

        $form->addButton(
            "§l§9Reload Config\n§r§fโหลดการตั้งค่าใหม่"
        );
        $actions[] = function(Player $target): void{
            $this->reloadConfig();

            foreach(
                $this->getServer()->getOnlinePlayers()
                as $online
            ){
                $this->applyRankPermissions($online);
                $this->updateNameTag($online);
                $this->spawnEquippedPet($online);
            }

            $this->showNotice(
                $target,
                "§aReload สำเร็จ",
                "§fโหลด §bconfig.yml §fใหม่เรียบร้อยแล้ว",
                true
            );
        };

        $form->addButton(
            "§l§fข้อมูลยศของฉัน\n§r§dเปิดหน้าผู้เล่น"
        );
        $actions[] = fn(Player $target) =>
            $this->showPlayerRankMenu($target);

        $player->sendForm($form);
    }

    private function showAssignRankForm(Player $player): void{
        $rankIds = $this->getOrderedRankIds();
        $rankNames = [];

        foreach($rankIds as $rankId){
            $rankNames[] =
                $this->getRankDisplay($rankId) .
                " §f(" . $rankId . ")";
        }

        $form = new CustomForm(
            function(Player $player, array $data) use ($rankIds): void{
                $targetName = trim((string) ($data[1] ?? ""));
                $rankIndex = (int) ($data[2] ?? -1);

                if(
                    $targetName === "" ||
                    !isset($rankIds[$rankIndex])
                ){
                    $this->showNotice(
                        $player,
                        "§cข้อมูลไม่ถูกต้อง",
                        "§fกรุณากรอกชื่อและเลือกยศ",
                        true
                    );
                    return;
                }

                $rankId = $rankIds[$rankIndex];

                if(!$this->setRank($targetName, $rankId)){
                    $this->showNotice(
                        $player,
                        "§cไม่สำเร็จ",
                        "§fไม่พบยศที่เลือก",
                        true
                    );
                    return;
                }

                $this->showNotice(
                    $player,
                    "§aกำหนดยศสำเร็จ",
                    "§fผู้เล่น: §b{$targetName}\n" .
                    "§fยศใหม่: §d" .
                    $this->getRankDisplay($rankId),
                    true
                );
            }
        );

        $form->setTitle("§l§aกำหนดยศผู้เล่น");
        $form->addLabel(
            "§fรองรับผู้เล่นออนไลน์และออฟไลน์"
        );
        $form->addInput(
            "§bชื่อผู้เล่น",
            "ตัวอย่าง: Steve"
        );
        $form->addDropdown(
            "§dเลือกยศ",
            $rankNames
        );

        $player->sendForm($form);
    }

    private function showColorForm(Player $player): void{
        $allowedStyles = $this->getAllowedStyles(
            $player->getName()
        );

        $styleNames = [];

        foreach($allowedStyles as $styleId){
            $styleNames[] = $this->getStyleDisplayName(
                $styleId
            );
        }

        $current = $this->getSelectedStyle(
            $player->getName()
        );

        $defaultIndex = array_search(
            $current,
            $allowedStyles,
            true
        );

        if($defaultIndex === false){
            $defaultIndex = 0;
        }

        $form = new CustomForm(
            function(Player $player, array $data) use (
                $allowedStyles
            ): void{
                $selected = (int) ($data[1] ?? -1);

                if(!isset($allowedStyles[$selected])){
                    return;
                }

                $styleId = $allowedStyles[$selected];

                if(
                    $styleId === "rainbow" &&
                    !$this->isAdministratorRank(
                        $player->getName()
                    )
                ){
                    $this->showNotice(
                        $player,
                        "§cใช้ Rainbow ไม่ได้",
                        "§fRainbow ใช้ได้เฉพาะยศแอดมินเท่านั้น"
                    );
                    return;
                }

                $this->setPlayerDataValue(
                    $player->getName(),
                    "style",
                    $styleId
                );

                $this->updateNameTag($player);

                $this->showNotice(
                    $player,
                    "§aเปลี่ยนสีสำเร็จ",
                    "§fสีใหม่: " .
                    $this->getStyleDisplayName($styleId),
                    $this->canManageRanks($player)
                );
            }
        );

        $form->setTitle("§l§d♡ เลือกสี ♡");
        $form->addLabel(
            $this->isAdministratorRank($player->getName())
                ? "§fยศแอดมินสามารถเลือก Rainbow ได้"
                : "§fRainbow สงวนไว้สำหรับยศแอดมิน"
        );
        $form->addDropdown(
            "§bเลือกสีที่ต้องการ",
            $styleNames,
            (int) $defaultIndex
        );

        $player->sendForm($form);
    }

    private function showRankCommandPicker(Player $player): void{
        $rankIds = $this->getOrderedRankIds();

        $form = new SimpleForm(
            function(Player $player, int $selected) use ($rankIds): void{
                if(!isset($rankIds[$selected])){
                    $this->showAdminMenu($player);
                    return;
                }

                $this->showRankCommandEditor(
                    $player,
                    $rankIds[$selected]
                );
            }
        );

        $form->setTitle("§l§eแก้คำสั่งประจำยศ");
        $form->setContent(
            "§fเลือกยศที่ต้องการแก้คำสั่ง"
        );

        foreach($rankIds as $rankId){
            $rank = $this->getRankConfig($rankId);
            $commands = $rank["commands"] ?? [];

            $form->addButton(
                "§l§d" .
                $this->getRankDisplay($rankId) .
                "\n§r§fคำสั่ง: §b" .
                (is_array($commands) ? count($commands) : 0)
            );
        }

        $form->addButton("§cย้อนกลับ");
        $player->sendForm($form);
    }

    private function showRankCommandEditor(
        Player $player,
        string $rankId
    ): void{
        $rank = $this->getRankConfig($rankId);
        $commands = $rank["commands"] ?? [];

        if(!is_array($commands)){
            $commands = [];
        }

        $form = new CustomForm(
            function(Player $player, array $data) use ($rankId): void{
                $input = trim((string) ($data[1] ?? ""));
                $commands = [];

                if($input !== ""){
                    foreach(explode(",", $input) as $command){
                        $command = strtolower(
                            ltrim(trim($command), "/")
                        );

                        if(
                            $command === "*" ||
                            preg_match(
                                "/^[a-z0-9_:-]+$/",
                                $command
                            ) === 1
                        ){
                            $commands[] = $command;
                        }
                    }
                }

                $commands = array_values(
                    array_unique($commands)
                );

                $this->getConfig()->setNested(
                    "ranks.{$rankId}.commands",
                    $commands
                );

                $this->getConfig()->save();

                foreach(
                    $this->getServer()->getOnlinePlayers()
                    as $online
                ){
                    if(
                        $this->getPlayerRank($online->getName()) ===
                        $rankId
                    ){
                        $this->applyRankPermissions($online);
                    }
                }

                $this->showNotice(
                    $player,
                    "§aแก้คำสั่งสำเร็จ",
                    "§fยศ: §d" .
                    $this->getRankDisplay($rankId) .
                    "\n§fจำนวนคำสั่ง: §b" .
                    count($commands),
                    true
                );
            }
        );

        $form->setTitle(
            "§l§eแก้คำสั่ง " .
            $this->getRankDisplay($rankId)
        );

        $form->addLabel(
            "§fคั่นคำสั่งด้วยเครื่องหมายจุลภาค\n" .
            "§dตัวอย่าง: §ffly, tp, fix, heal\n" .
            "§cไม่ต้องใส่ / ด้านหน้า"
        );

        $form->addInput(
            "§bรายการคำสั่ง",
            "fly, tp, fix",
            implode(", ", array_map("strval", $commands))
        );

        $player->sendForm($form);
    }

    private function showRankCommands(Player $player): void{
        $commands = $this->getAllowedCommands($player);

        $descriptions = $this->getConfig()->get(
            "command-descriptions",
            []
        );

        $player->sendForm(
            $this->buildRankCommandsForm(
                $player,
                $commands,
                $descriptions
            )
        );
    }

    private function buildRankCommandsForm(
        Player $player,
        array $commands,
        mixed $descriptions
    ): SimpleForm{
        $form = new SimpleForm(
            function(Player $player, int $selected) use (
                $commands
            ): void{
                if($selected < 0){
                    return;
                }

                if(isset($commands[$selected])){
                    $this->openRankCommandFromUi(
                        $player,
                        (string) $commands[$selected]
                    );
                    return;
                }

                if($this->canManageRanks($player)){
                    $this->showAdminMenu($player);
                }else{
                    $this->showPlayerRankMenu($player);
                }
            }
        );

        $form->setTitle("§l§b♡ คำสั่งประจำยศ ♡");

        $form->setContent(
            "§fยศของคุณ: " .
            $this->renderRankPrefix(
                $player->getName(),
                $this->rainbowPhase()
            ) .
            "\n\n§7เลือกคำสั่งเพื่อใช้งาน"
        );

        foreach($commands as $command){
            $description = is_array($descriptions)
                ? (string) (
                    $descriptions[$command] ??
                    "คำสั่งพิเศษประจำยศ"
                )
                : "คำสั่งพิเศษประจำยศ";

            $form->addButton(
                "§l§d/{$command}\n§r§f{$description}"
            );
        }

        $form->addButton("§d↩ กลับ");

        return $form;
    }

    private function openRankCommandFromUi(
        Player $player,
        string $commandName
    ): void{
        $commandName = $this->normalizeCommand(
            $commandName
        );

        if(!$this->canUseRankCommand($player, $commandName)){
            $this->showNotice(
                $player,
                "§cไม่มีสิทธิ์ใช้งาน",
                "§fยศของคุณไม่สามารถใช้ §d/" .
                $commandName .
                " §fได้"
            );
            return;
        }

        switch($commandName){
            case "fly":
            case "fix":
            case "heal":
                $this->executeRankCommand(
                    $player,
                    $commandName,
                    []
                );

                $this->showRankCommands($player);
                return;

            case "pets":
                $this->showPetMenu($player);
                return;

            case "rankrename":
                $this->showRankRenameForm($player);
                return;

            case "tp":
                $this->showRankCommandInput(
                    $player,
                    "tp",
                    "§l§bวาร์ปไปหาผู้เล่น",
                    [
                        [
                            "label" => "§dชื่อผู้เล่น",
                            "placeholder" => "ตัวอย่าง: Steve",
                            "default" => ""
                        ]
                    ]
                );
                return;

            case "enchant":
                $this->showRankCommandInput(
                    $player,
                    "enchant",
                    "§l§dเพิ่มมนตร์",
                    [
                        [
                            "label" => "§bชื่อมนตร์",
                            "placeholder" => "ตัวอย่าง: sharpness",
                            "default" => ""
                        ],
                        [
                            "label" => "§eเลเวล",
                            "placeholder" => "1-255",
                            "default" => "1"
                        ]
                    ]
                );
                return;

            case "nick":
                $this->showRankCommandInput(
                    $player,
                    "nick",
                    "§l§dเปลี่ยนชื่อเล่น",
                    [
                        [
                            "label" => "§bชื่อใหม่",
                            "placeholder" => "ใส่ off เพื่อรีเซ็ต",
                            "default" => ""
                        ]
                    ]
                );
                return;

            case "size":
                $this->showRankCommandInput(
                    $player,
                    "size",
                    "§l§aเปลี่ยนขนาด",
                    [
                        [
                            "label" => "§bขนาดตัวละคร",
                            "placeholder" => "ตัวอย่าง: 1.5",
                            "default" => "1.0"
                        ]
                    ]
                );
                return;

            case "time":
                $this->showRankCommandInput(
                    $player,
                    "time",
                    "§l§eเปลี่ยนเวลา",
                    [
                        [
                            "label" => "§bเวลา",
                            "placeholder" => "day, night, sunrise, sunset",
                            "default" => "day"
                        ]
                    ]
                );
                return;

            case "kick":
                $this->showRankCommandInput(
                    $player,
                    "kick",
                    "§l§cนำผู้เล่นออก",
                    [
                        [
                            "label" => "§bชื่อผู้เล่น",
                            "placeholder" => "ตัวอย่าง: Steve",
                            "default" => ""
                        ],
                        [
                            "label" => "§eเหตุผล",
                            "placeholder" => "กรอกเหตุผล",
                            "default" => "ทำผิดกฎของเซิร์ฟเวอร์"
                        ]
                    ]
                );
                return;

            case "readmin":
                $this->showRankCommandInput(
                    $player,
                    "readmin",
                    "§l§cเรียกแอดมิน",
                    [
                        [
                            "label" => "§eข้อความถึงแอดมิน",
                            "placeholder" => "บอกปัญหาที่พบ",
                            "default" => ""
                        ]
                    ]
                );
                return;
        }

        // คำสั่งอื่นที่แอดมินเพิ่มใน config
        $player->sendMessage(
            $this->prefix() .
            "§eกำลังเรียกใช้ §f/{$commandName}"
        );

        $success = $this->getServer()->dispatchCommand(
            $player,
            $commandName
        );

        if(!$success){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบคำสั่ง §f/" .
                $commandName .
                " §cในเซิร์ฟเวอร์"
            );
        }
    }

    private function showRankCommandInput(
        Player $player,
        string $commandName,
        string $title,
        array $fields
    ): void{
        $form = new CustomForm(
            function(Player $player, array $data) use (
                $commandName,
                $fields
            ): void{
                if(!$this->canUseRankCommand(
                    $player,
                    $commandName
                )){
                    $player->sendMessage(
                        $this->prefix() .
                        "§cยศของคุณไม่สามารถใช้คำสั่งนี้ได้"
                    );
                    return;
                }

                $values = [];

                foreach($fields as $index => $field){
                    $values[] = trim(
                        (string) ($data[$index + 1] ?? "")
                    );
                }

                $args = match($commandName){
                    "nick", "readmin" => isset($values[0])
                        ? preg_split(
                            "/\s+/",
                            $values[0],
                            -1,
                            PREG_SPLIT_NO_EMPTY
                        ) ?: []
                        : [],

                    "kick" => array_values(
                        array_filter(
                            [
                                $values[0] ?? "",
                                ...(
                                    preg_split(
                                        "/\s+/",
                                        $values[1] ?? "",
                                        -1,
                                        PREG_SPLIT_NO_EMPTY
                                    ) ?: []
                                )
                            ],
                            static fn(string $value): bool =>
                                $value !== ""
                        )
                    ),

                    default => array_values(
                        array_filter(
                            $values,
                            static fn(string $value): bool =>
                                $value !== ""
                        )
                    )
                };

                if(
                    in_array(
                        $commandName,
                        ["tp", "enchant", "nick", "size", "time", "kick", "readmin"],
                        true
                    ) &&
                    count($args) === 0
                ){
                    $player->sendMessage(
                        $this->prefix() .
                        "§cกรุณากรอกข้อมูลให้ครบ"
                    );
                    return;
                }

                $this->executeRankCommand(
                    $player,
                    $commandName,
                    $args
                );
            }
        );

        $form->setTitle($title);

        $form->addLabel(
            "§fกรอกข้อมูลแล้วกดส่งเพื่อใช้คำสั่ง §d/" .
            $commandName
        );

        foreach($fields as $field){
            $form->addInput(
                (string) ($field["label"] ?? "§bข้อมูล"),
                (string) ($field["placeholder"] ?? ""),
                (string) ($field["default"] ?? "")
            );
        }

        $player->sendForm($form);
    }

    private function showRankRenameForm(Player $player): void{
        if(!$this->canUseRankCommand($player, "rankrename")){
            $this->showNotice(
                $player,
                "§cไม่สามารถเปลี่ยนได้",
                "§fใช้ได้เฉพาะยศพิเศษและยศแอดมิน"
            );
            return;
        }

        $availableAt = (int) $this->getPlayerDataValue(
            $player->getName(),
            "rank-rename-available-at",
            0
        );

        if(
            !$this->isAdministratorRank($player->getName()) &&
            $availableAt > time()
        ){
            $remainingDays = (int) ceil(
                ($availableAt - time()) / 86400
            );

            $this->showNotice(
                $player,
                "§cยังเปลี่ยนไม่ได้",
                "§fเปลี่ยนได้อีกครั้งใน §d" .
                $remainingDays .
                " §fวัน"
            );

            return;
        }

        $current = (string) $this->getPlayerDataValue(
            $player->getName(),
            "custom-rank",
            ""
        );

        $form = new CustomForm(
            function(Player $player, array $data): void{
                $input = trim((string) ($data[1] ?? ""));

                if($input === ""){
                    $this->showNotice(
                        $player,
                        "§cชื่อไม่ถูกต้อง",
                        "§fกรุณากรอกชื่อยศใหม่"
                    );
                    return;
                }

                $requestRainbow = str_starts_with(
                    strtolower($input),
                    "rainbow:"
                );

                if(
                    $requestRainbow &&
                    !$this->isAdministratorRank(
                        $player->getName()
                    )
                ){
                    $this->showNotice(
                        $player,
                        "§cใช้ Rainbow ไม่ได้",
                        "§fRainbow ใช้ได้เฉพาะยศแอดมิน"
                    );
                    return;
                }

                $nameToCheck = $requestRainbow
                    ? trim(substr($input, 8))
                    : $input;

                $cleanName = TextFormat::clean(
                    $this->translateColors($nameToCheck)
                );

                $maximumLength = max(
                    1,
                    (int) $this->getConfig()->getNested(
                        "settings.maximum-rank-name-length",
                        20
                    )
                );

                if(
                    $cleanName === "" ||
                    mb_strlen($cleanName) > $maximumLength
                ){
                    $this->showNotice(
                        $player,
                        "§cชื่อไม่ถูกต้อง",
                        "§fชื่อยศต้องไม่เกิน §d" .
                        $maximumLength .
                        " §fตัวอักษร"
                    );
                    return;
                }

                $savedName = $requestRainbow
                    ? "rainbow:" . $cleanName
                    : $this->filterCustomColorText($input);

                $this->setPlayerDataValue(
                    $player->getName(),
                    "custom-rank",
                    $savedName,
                    false
                );

                if(!$this->isAdministratorRank($player->getName())){
                    $days = max(
                        1,
                        (int) $this->getConfig()->getNested(
                            "settings.rank-rename-days",
                            30
                        )
                    );

                    $this->setPlayerDataValue(
                        $player->getName(),
                        "rank-rename-available-at",
                        time() + ($days * 86400),
                        false
                    );
                }

                $this->playerData->save();
                $this->updateNameTag($player);

                $this->showNotice(
                    $player,
                    "§aเปลี่ยนชื่อยศสำเร็จ",
                    "§fชื่อใหม่: " .
                    $this->renderRankPrefix(
                        $player->getName(),
                        $this->rainbowPhase()
                    )
                );
            }
        );

        $form->setTitle("§l§d♡ เปลี่ยนชื่อยศ ♡");
        $form->addLabel(
            "§fใช้โค้ดสีแบบ §d&d §b&b §e&e §a&a\n" .
            "§fตัวอย่าง: §d&dCute Angel\n" .
            "§fRainbow ใช้ได้เฉพาะยศแอดมิน"
        );
        $form->addInput(
            "§dชื่อยศใหม่",
            "ตัวอย่าง: &dCute Angel",
            $current
        );

        $player->sendForm($form);
    }

    private function showPetMenu(Player $player): void{
        if(!(bool) $this->getConfig()->getNested(
            "pets.enabled",
            true
        )){
            $this->showNotice(
                $player,
                "§cระบบปิดใช้งาน",
                "§fขณะนี้ระบบสัตว์เลี้ยงถูกปิด"
            );
            return;
        }

        $money = $this->simpleMoney
            ->getEconomy()
            ->getMoney($player);

        $pets = $this->getOwnedPets($player->getName());
        $equipped = (string) $this->getPlayerDataValue(
            $player->getName(),
            "equipped-pet",
            ""
        );

        $actions = [];

        $form = new SimpleForm(
            function(Player $player, int $selected) use (&$actions): void{
                if(isset($actions[$selected])){
                    $actions[$selected]($player);
                }
            }
        );

        $form->setTitle("§l§d♡ §eสัตว์เลี้ยง §d♡");

        $form->setContent(
            "§fเงินของคุณ: §a" .
            number_format($money, 2) .
            " §fบาท\n" .
            "§fสัตว์ที่มี: §b" .
            count($pets) .
            "\n§fสัตว์ที่กำลังใช้: " .
            (
                $equipped !== "" && isset($pets[$equipped])
                    ? $this->formatPetName($pets[$equipped])
                    : "§cไม่ได้เลือก"
            )
        );

        $form->addButton(
            "§l§aซื้อสัตว์เลี้ยง\n§r§fราคา " .
            number_format(
                (float) $this->getConfig()->getNested(
                    "pets.price",
                    50000
                )
            ) .
            " บาท"
        );
        $actions[] = fn(Player $target) =>
            $this->showPetPurchaseForm($target);

        $form->addButton(
            "§l§bสัตว์เลี้ยงของฉัน\n§r§fเลือกตัวที่ต้องการใช้งาน"
        );
        $actions[] = fn(Player $target) =>
            $this->showOwnedPets($target);

        if($equipped !== ""){
            $form->addButton(
                "§l§cเก็บสัตว์เลี้ยง\n§r§fหยุดเรียกสัตว์ปัจจุบัน"
            );
            $actions[] = function(Player $target): void{
                $this->setPlayerDataValue(
                    $target->getName(),
                    "equipped-pet",
                    ""
                );

                $this->removeActivePet($target->getName());
                $this->showPetMenu($target);
            };
        }

        if($this->canManageRanks($player)){
            $form->addButton(
                "§l§6แจกสัตว์เลี้ยง\n§r§fเมนูสำหรับแอดมิน"
            );
            $actions[] = fn(Player $target) =>
                $this->showPetAdminForm($target);
        }

        $form->addButton("§dกลับหน้าระบบยศ");
        $actions[] = fn(Player $target) =>
            $this->showPlayerRankMenu($target);

        $player->sendForm($form);
    }

    private function showPetPurchaseForm(Player $player): void{
        $types = $this->getPetTypes();
        $typeIds = array_keys($types);
        $typeNames = [];

        foreach($typeIds as $typeId){
            $config = $types[$typeId];

            $typeNames[] =
                (string) ($config["display-name"] ?? $typeId) .
                " §f- " .
                (string) (
                    $config["ability-name"] ??
                    "ไม่มีความสามารถ"
                );
        }

        $price = (float) $this->getConfig()->getNested(
            "pets.price",
            50000
        );

        $form = new CustomForm(
            function(Player $player, array $data) use (
                $typeIds,
                $price
            ): void{
                $selected = (int) ($data[1] ?? -1);
                $confirm = (bool) ($data[2] ?? false);

                if(!$confirm || !isset($typeIds[$selected])){
                    $this->showPetMenu($player);
                    return;
                }

                $this->purchasePet(
                    $player,
                    $typeIds[$selected],
                    $price
                );
            }
        );

        $form->setTitle("§l§aซื้อสัตว์เลี้ยง");
        $form->addLabel(
            "§fราคา: §a" .
            number_format($price, 2) .
            " §fบาท\n" .
            "§fระดับจะถูกสุ่มตั้งแต่ §fกาก §fถึง §dระดับเทพ"
        );
        $form->addDropdown(
            "§bเลือกชนิดสัตว์",
            $typeNames
        );
        $form->addToggle(
            "§aยืนยันการซื้อ",
            false
        );

        $player->sendForm($form);
    }

    private function showOwnedPets(Player $player): void{
        $pets = $this->getOwnedPets($player->getName());
        $petIds = array_keys($pets);
        $equipped = (string) $this->getPlayerDataValue(
            $player->getName(),
            "equipped-pet",
            ""
        );

        $form = new SimpleForm(
            function(Player $player, int $selected) use ($petIds): void{
                if(!isset($petIds[$selected])){
                    $this->showPetMenu($player);
                    return;
                }

                $petId = $petIds[$selected];

                $this->setPlayerDataValue(
                    $player->getName(),
                    "equipped-pet",
                    $petId
                );

                $this->spawnEquippedPet($player);

                $this->showNotice(
                    $player,
                    "§aเรียกสัตว์สำเร็จ",
                    "§fสัตว์เลี้ยงจะติดตามและมอบความสามารถให้คุณ"
                );
            }
        );

        $form->setTitle("§l§bสัตว์เลี้ยงของฉัน");

        $form->setContent(
            count($pets) > 0
                ? "§fเลือกสัตว์ที่ต้องการเรียกใช้งาน"
                : "§eคุณยังไม่มีสัตว์เลี้ยง"
        );

        foreach($pets as $petId => $petData){
            $selected = $petId === $equipped
                ? " §a[กำลังใช้]"
                : "";

            $form->addButton(
                $this->formatPetName($petData) .
                $selected .
                "\n§r§fความสามารถ: " .
                $this->getPetAbilityDisplay($petData)
            );
        }

        $form->addButton("§dย้อนกลับ");
        $player->sendForm($form);
    }

    private function showPetAdminForm(Player $player): void{
        if(!$this->canManageRanks($player)){
            return;
        }

        $types = $this->getPetTypes();
        $typeIds = array_keys($types);
        $typeNames = [];

        foreach($typeIds as $typeId){
            $typeNames[] = (string) (
                $types[$typeId]["display-name"] ??
                $typeId
            );
        }

        $rarities = $this->getPetRarities();
        $rarityIds = array_keys($rarities);

        $rarityOptions = ["§dสุ่มระดับ"];

        foreach($rarityIds as $rarityId){
            $rarityOptions[] = (string) (
                $rarities[$rarityId]["display-name"] ??
                $rarityId
            );
        }

        $form = new CustomForm(
            function(Player $player, array $data) use (
                $typeIds,
                $rarityIds
            ): void{
                $targetName = trim((string) ($data[1] ?? ""));
                $typeIndex = (int) ($data[2] ?? -1);
                $rarityIndex = (int) ($data[3] ?? 0);
                $equip = (bool) ($data[4] ?? true);

                if(
                    $targetName === "" ||
                    !isset($typeIds[$typeIndex])
                ){
                    $this->showNotice(
                        $player,
                        "§cข้อมูลไม่ถูกต้อง",
                        "§fกรุณากรอกชื่อและเลือกชนิดสัตว์",
                        true
                    );
                    return;
                }

                $rarityId = $rarityIndex === 0
                    ? null
                    : ($rarityIds[$rarityIndex - 1] ?? null);

                $result = $this->grantPet(
                    $targetName,
                    $typeIds[$typeIndex],
                    $rarityId,
                    $equip
                );

                if(!($result["success"] ?? false)){
                    $this->showNotice(
                        $player,
                        "§cแจกสัตว์ไม่สำเร็จ",
                        "§f" .
                        (string) (
                            $result["message"] ??
                            "เกิดข้อผิดพลาด"
                        ),
                        true
                    );
                    return;
                }

                $this->showNotice(
                    $player,
                    "§aแจกสัตว์สำเร็จ",
                    "§fผู้เล่น: §b{$targetName}\n" .
                    "§fสัตว์: " .
                    $this->formatPetName(
                        (array) $result["pet"]
                    ),
                    true
                );
            }
        );

        $form->setTitle("§l§6แจกสัตว์เลี้ยง");
        $form->addLabel(
            "§fแอดมินแจกได้ฟรีและเลือกระดับได้"
        );
        $form->addInput(
            "§bชื่อผู้เล่น",
            "ตัวอย่าง: Steve"
        );
        $form->addDropdown(
            "§dชนิดสัตว์",
            $typeNames
        );
        $form->addDropdown(
            "§eระดับสัตว์",
            $rarityOptions
        );
        $form->addToggle(
            "§aเรียกสัตว์ตัวนี้ทันที",
            true
        );

        $player->sendForm($form);
    }

    private function purchasePet(
        Player $player,
        string $typeId,
        float $price
    ): void{
        $maximum = max(
            1,
            (int) $this->getConfig()->getNested(
                "pets.maximum-owned",
                20
            )
        );

        if(count($this->getOwnedPets($player->getName())) >= $maximum){
            $this->showNotice(
                $player,
                "§cซื้อไม่ได้",
                "§fคุณมีสัตว์เลี้ยงครบ §d{$maximum} §fตัวแล้ว"
            );
            return;
        }

        $economy = $this->simpleMoney->getEconomy();
        $currentMoney = $economy->getMoney($player);

        if($currentMoney < $price){
            $this->showNotice(
                $player,
                "§cเงินไม่เพียงพอ",
                "§fต้องการ §a" .
                number_format($price, 2) .
                " §fบาท\n" .
                "§fคุณมี §c" .
                number_format($currentMoney, 2) .
                " §fบาท"
            );
            return;
        }

        if(!$economy->withdraw(
            $player,
            $price,
            "cuteranks_pet_purchase"
        )){
            $this->showNotice(
                $player,
                "§cหักเงินไม่สำเร็จ",
                "§fSimpleMoney ไม่สามารถหักยอดเงินได้"
            );
            return;
        }

        try{
            $result = $this->grantPet(
                $player->getName(),
                $typeId,
                null,
                true
            );

            if(!($result["success"] ?? false)){
                $economy->deposit(
                    $player,
                    $price,
                    "cuteranks_pet_refund"
                );

                $this->showNotice(
                    $player,
                    "§cซื้อไม่สำเร็จ",
                    "§fระบบคืนเงินให้แล้ว"
                );
                return;
            }

            $pet = (array) $result["pet"];

            $this->showNotice(
                $player,
                "§aซื้อสัตว์สำเร็จ §d♡",
                "§fคุณได้รับ: " .
                $this->formatPetName($pet) .
                "\n§fความสามารถ: " .
                $this->getPetAbilityDisplay($pet)
            );
        }catch(Throwable $throwable){
            $economy->deposit(
                $player,
                $price,
                "cuteranks_pet_error_refund"
            );

            $this->getLogger()->error(
                "สร้างสัตว์เลี้ยงไม่สำเร็จ: " .
                $throwable->getMessage()
            );

            $this->showNotice(
                $player,
                "§cเกิดข้อผิดพลาด",
                "§fระบบคืนเงินให้เรียบร้อยแล้ว"
            );
        }
    }

    /**
     * API สำหรับแอดมินหรือปลั๊กอิน Topup
     *
     * @return array<string, mixed>
     */
    public function grantPet(
        string $playerName,
        string $typeId,
        ?string $rarityId = null,
        bool $equip = true
    ): array{
        $types = $this->getPetTypes();

        if(!isset($types[$typeId])){
            return [
                "success" => false,
                "message" => "ไม่พบชนิดสัตว์ {$typeId}"
            ];
        }

        if($rarityId === null){
            $rarityId = $this->randomPetRarity();
        }

        $rarities = $this->getPetRarities();

        if(!isset($rarities[$rarityId])){
            return [
                "success" => false,
                "message" => "ไม่พบระดับสัตว์ {$rarityId}"
            ];
        }

        $this->ensurePlayerData($playerName);

        $petId = strtolower($typeId) .
            "-" .
            bin2hex(random_bytes(4));

        $petData = [
            "id" => $petId,
            "type" => $typeId,
            "rarity" => $rarityId,
            "created-at" => time()
        ];

        $key = $this->playerKey($playerName);

        $this->playerData->setNested(
            "players.{$key}.pets.{$petId}",
            $petData
        );

        if($equip){
            $this->playerData->setNested(
                "players.{$key}.equipped-pet",
                $petId
            );
        }

        $this->playerData->save();

        $online = $this->findOnlinePlayer($playerName);

        if($online !== null && $equip){
            $this->spawnEquippedPet($online);
        }

        return [
            "success" => true,
            "pet-id" => $petId,
            "pet" => $petData
        ];
    }

    private function spawnEquippedPet(Player $player): void{
        $this->removeActivePet($player->getName());

        if(!$player->isConnected()){
            return;
        }

        if(!(bool) $this->getConfig()->getNested(
            "pets.enabled",
            true
        )){
            return;
        }

        $petId = (string) $this->getPlayerDataValue(
            $player->getName(),
            "equipped-pet",
            ""
        );

        if($petId === ""){
            return;
        }

        $pets = $this->getOwnedPets($player->getName());
        $petData = $pets[$petId] ?? null;

        if(!is_array($petData)){
            $this->setPlayerDataValue(
                $player->getName(),
                "equipped-pet",
                ""
            );
            return;
        }

        $typeId = strtolower(
            (string) ($petData["type"] ?? "")
        );

        $rarityId = strtolower(
            (string) ($petData["rarity"] ?? "")
        );

        $types = $this->getPetTypes();
        $rarities = $this->getPetRarities();

        $type = $types[$typeId] ?? null;
        $rarity = $rarities[$rarityId] ?? null;

        if(!is_array($type)){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบชนิดสัตว์ §f{$typeId} §cใน config.yml"
            );
            return;
        }

        if(!is_array($rarity)){
            $player->sendMessage(
                $this->prefix() .
                "§cไม่พบระดับสัตว์ §f{$rarityId} §cใน config.yml"
            );
            return;
        }

        $typeName = TextFormat::clean(
            (string) ($type["display-name"] ?? $typeId)
        );

        $rarityName = TextFormat::clean(
            (string) ($rarity["display-name"] ?? $rarityId)
        );

        $rarityColor = (string) (
            $rarity["color"] ?? "§f"
        );

        $displayName =
            $rarityColor .
            $typeName .
            " §f[" .
            $rarityColor .
            $rarityName .
            "§f]\n" .
            "§d♡ §fเจ้าของ §b" .
            $player->getName() .
            " §d♡";

        $ability = (string) (
            $type["ability"] ?? "speed"
        );

        $amplifier = max(
            0,
            (int) ($rarity["amplifier"] ?? 0)
        );

        $scale =
            (float) ($type["scale"] ?? 0.7) +
            (float) ($rarity["scale-bonus"] ?? 0.0);

        $location = $player->getLocation();
        $direction = $player->getDirectionVector();

        $spawnLocation = new Location(
            $location->x - ($direction->x * 1.5),
            $location->y + 0.3,
            $location->z - ($direction->z * 1.5),
            $location->getWorld(),
            $location->yaw,
            0.0
        );

        try{
            $pet = match($typeId){
                "wolf" => new WolfPet(
                    $spawnLocation,
                    $player->getName(),
                    $displayName,
                    $ability,
                    $amplifier,
                    $scale
                ),
                "rabbit" => new RabbitPet(
                    $spawnLocation,
                    $player->getName(),
                    $displayName,
                    $ability,
                    $amplifier,
                    $scale
                ),
                "fox" => new FoxPet(
                    $spawnLocation,
                    $player->getName(),
                    $displayName,
                    $ability,
                    $amplifier,
                    $scale
                ),
                "cat" => new CatPet(
                    $spawnLocation,
                    $player->getName(),
                    $displayName,
                    $ability,
                    $amplifier,
                    $scale
                ),
                "bee" => new BeePet(
                    $spawnLocation,
                    $player->getName(),
                    $displayName,
                    $ability,
                    $amplifier,
                    $scale
                ),
                default => null
            };

            if($pet === null){
                $player->sendMessage(
                    $this->prefix() .
                    "§cยังไม่มี Entity สำหรับสัตว์ชนิด §f{$typeId}"
                );
                return;
            }

            $pet->spawnToAll();

            $this->activePets[
                $this->playerKey($player->getName())
            ] = $pet;

            $this->applyPetAbility($player, $pet);
        }catch(Throwable $throwable){
            $this->getLogger()->error(
                "ไม่สามารถเรียกสัตว์ของ {$player->getName()}: " .
                $throwable->getMessage()
            );

            $player->sendMessage(
                $this->prefix() .
                "§cเรียกสัตว์ไม่สำเร็จ กรุณาตรวจสอบ Console"
            );
        }
    }

    private function updatePets(): void{
        $abilityTicks = max(
            20,
            (int) $this->getConfig()->getNested(
                "pets.ability-update-ticks",
                80
            )
        );

        foreach($this->activePets as $key => $pet){
            if($pet->isClosed()){
                unset($this->activePets[$key]);
                continue;
            }

            $owner = $this->findOnlinePlayer(
                $pet->getOwnerName()
            );

            if($owner === null){
                $pet->flagForDespawn();
                unset($this->activePets[$key]);
                continue;
            }

            $pet->follow($owner);

            if($this->petTicks % $abilityTicks === 0){
                $this->applyPetAbility($owner, $pet);
            }
        }
    }

    private function applyPetAbility(
        Player $player,
        PetEntity $pet
    ): void{
        $amplifier = max(
            0,
            min(4, $pet->getRarityAmplifier())
        );

        $effect = match($pet->getAbility()){
            "strength" => VanillaEffects::STRENGTH(),
            "jump" => VanillaEffects::JUMP_BOOST(),
            "speed" => VanillaEffects::SPEED(),
            "night_vision" => VanillaEffects::NIGHT_VISION(),
            "regeneration" => VanillaEffects::REGENERATION(),
            default => null
        };

        if($effect === null){
            return;
        }

        $player->getEffects()->add(
            new EffectInstance(
                $effect,
                120,
                $amplifier,
                false
            )
        );
    }

    private function removeActivePet(string $playerName): void{
        $key = $this->playerKey($playerName);

        if(!isset($this->activePets[$key])){
            return;
        }

        $pet = $this->activePets[$key];

        if(!$pet->isClosed()){
            $pet->flagForDespawn();
        }

        unset($this->activePets[$key]);
    }

    private function randomPetRarity(): string{
        $rarities = $this->getPetRarities();

        if(count($rarities) === 0){
            return "poor";
        }

        $totalWeight = 0;

        foreach($rarities as $rarity){
            $totalWeight += max(
                0,
                (int) ($rarity["weight"] ?? 0)
            );
        }

        if($totalWeight <= 0){
            return (string) array_key_first($rarities);
        }

        $roll = random_int(1, $totalWeight);
        $current = 0;

        foreach($rarities as $rarityId => $rarity){
            $current += max(
                0,
                (int) ($rarity["weight"] ?? 0)
            );

            if($roll <= $current){
                return (string) $rarityId;
            }
        }

        return (string) array_key_first($rarities);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getOwnedPets(string $playerName): array{
        $pets = $this->getPlayerDataValue(
            $playerName,
            "pets",
            []
        );

        if(!is_array($pets)){
            return [];
        }

        return array_filter(
            $pets,
            static fn(mixed $pet): bool => is_array($pet)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getPetTypes(): array{
        $types = $this->getConfig()->getNested(
            "pets.types",
            []
        );

        return is_array($types)
            ? array_filter(
                $types,
                static fn(mixed $value): bool =>
                    is_array($value)
            )
            : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getPetRarities(): array{
        $rarities = $this->getConfig()->getNested(
            "pets.rarities",
            []
        );

        return is_array($rarities)
            ? array_filter(
                $rarities,
                static fn(mixed $value): bool =>
                    is_array($value)
            )
            : [];
    }

    /**
     * @param array<string, mixed> $petData
     */
    private function formatPetName(array $petData): string{
        $typeId = (string) ($petData["type"] ?? "");
        $rarityId = (string) ($petData["rarity"] ?? "");

        $types = $this->getPetTypes();
        $rarities = $this->getPetRarities();

        $typeName = (string) (
            $types[$typeId]["display-name"] ??
            $typeId
        );

        $rarityName = (string) (
            $rarities[$rarityId]["display-name"] ??
            $rarityId
        );

        return $typeName . " §f[" . $rarityName . "§f]";
    }

    /**
     * @param array<string, mixed> $petData
     */
    private function getPetAbilityDisplay(array $petData): string{
        $typeId = (string) ($petData["type"] ?? "");
        $types = $this->getPetTypes();

        return (string) (
            $types[$typeId]["ability-name"] ??
            "ไม่มีความสามารถ"
        );
    }

    private function consumeKickUsage(Player $player): bool{
        $limit = max(
            1,
            (int) $this->getConfig()->getNested(
                "settings.kick-limit",
                2
            )
        );

        $periodDays = max(
            1,
            (int) $this->getConfig()->getNested(
                "settings.kick-period-days",
                3
            )
        );

        $periodSeconds = $periodDays * 86400;

        $windowStart = (int) $this->getPlayerDataValue(
            $player->getName(),
            "kick-window-start",
            0
        );

        $count = (int) $this->getPlayerDataValue(
            $player->getName(),
            "kick-count",
            0
        );

        if(
            $windowStart <= 0 ||
            time() - $windowStart >= $periodSeconds
        ){
            $windowStart = time();
            $count = 0;
        }

        if($count >= $limit){
            return false;
        }

        $this->setPlayerDataValue(
            $player->getName(),
            "kick-window-start",
            $windowStart,
            false
        );

        $this->setPlayerDataValue(
            $player->getName(),
            "kick-count",
            $count + 1,
            false
        );

        $this->playerData->save();

        return true;
    }

    private function registerPetEntities(): void{
        $factory = EntityFactory::getInstance();

        $entities = [
            [
                WolfPet::class,
                [
                    "cuteranks:wolf_pet",
                    "CuteRanksWolfPet"
                ]
            ],
            [
                RabbitPet::class,
                [
                    "cuteranks:rabbit_pet",
                    "CuteRanksRabbitPet"
                ]
            ],
            [
                FoxPet::class,
                [
                    "cuteranks:fox_pet",
                    "CuteRanksFoxPet"
                ]
            ],
            [
                CatPet::class,
                [
                    "cuteranks:cat_pet",
                    "CuteRanksCatPet"
                ]
            ],
            [
                BeePet::class,
                [
                    "cuteranks:bee_pet",
                    "CuteRanksBeePet"
                ]
            ]
        ];

        foreach($entities as [$entityClass, $saveNames]){
            if($factory->isRegistered($entityClass)){
                continue;
            }

            try{
                $factory->register(
                    $entityClass,
                    static function(
                        World $world,
                        CompoundTag $nbt
                    ) use ($entityClass): PetEntity{
                        $location = EntityDataHelper::parseLocation(
                            $nbt,
                            $world
                        );

                        return new $entityClass(
                            $location,
                            $nbt->getString(
                                "CuteRanksOwner",
                                "Unknown"
                            ),
                            $nbt->getString(
                                "CuteRanksDisplay",
                                "§dสัตว์เลี้ยง"
                            ),
                            $nbt->getString(
                                "CuteRanksAbility",
                                "speed"
                            ),
                            $nbt->getInt(
                                "CuteRanksAmplifier",
                                0
                            ),
                            $nbt->getFloat(
                                "CuteRanksScale",
                                0.7
                            ),
                            $nbt
                        );
                    },
                    $saveNames
                );
            }catch(Throwable $throwable){
                $this->getLogger()->warning(
                    "ลงทะเบียนสัตว์ {$entityClass} ไม่สำเร็จ: " .
                    $throwable->getMessage()
                );
            }
        }
    }

    private function preparePlayer(Player $player): void{
        $this->ensurePlayerData($player->getName());

        $this->setPlayerDataValue(
            $player->getName(),
            "display-name",
            $player->getName(),
            false
        );

        $this->playerData->save();

        $this->simpleMoney
            ->getEconomy()
            ->ensureAccount($player);

        $this->applyRankPermissions($player);
        $this->updateNameTag($player);

        $playerName = $player->getName();

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function() use ($playerName): void{
                $online = $this->findOnlinePlayer($playerName);

                if($online === null || !$online->isConnected()){
                    return;
                }

                $this->spawnEquippedPet($online);
            }),
            20
        );
    }

    private function ensurePlayerData(string $playerName): void{
        $key = $this->playerKey($playerName);

        if(
            $this->playerData->getNested(
                "players.{$key}"
            ) !== null
        ){
            return;
        }

        $this->playerData->setNested(
            "players.{$key}",
            [
                "display-name" => $playerName,
                "rank" => $this->getDefaultRank(),
                "style" => "white",
                "nickname" => $playerName,
                "custom-rank" => "",
                "rank-rename-available-at" => 0,
                "kick-window-start" => 0,
                "kick-count" => 0,
                "equipped-pet" => "",
                "pets" => []
            ]
        );

        $this->playerData->save();
    }

    /**
     * Public API สำหรับ TopupShop
     */
    public function setRank(
        string $playerName,
        string $rankId
    ): bool{
        $rankId = strtolower(trim($rankId));

        if(!$this->rankExists($rankId)){
            return false;
        }

        $this->ensurePlayerData($playerName);

        $key = $this->playerKey($playerName);

        $this->playerData->setNested(
            "players.{$key}.rank",
            $rankId
        );

        $this->playerData->setNested(
            "players.{$key}.display-name",
            $playerName
        );

        $this->playerData->save();

        $online = $this->findOnlinePlayer($playerName);

        if($online !== null){
            $this->applyRankPermissions($online);
            $this->updateNameTag($online);

            $online->sendMessage(
                $this->prefix() .
                "§aยศของคุณเปลี่ยนเป็น §d" .
                $this->getRankDisplay($rankId) .
                " §aแล้ว"
            );
        }

        return true;
    }

    public function getPlayerRank(string $playerName): string{
        $rankId = strtolower(
            (string) $this->getPlayerDataValue(
                $playerName,
                "rank",
                $this->getDefaultRank()
            )
        );

        return $this->rankExists($rankId)
            ? $rankId
            : $this->getDefaultRank();
    }

    private function applyRankPermissions(Player $player): void{
        $this->removeAttachment($player);

        $rank = $this->getRankConfig(
            $this->getPlayerRank($player->getName())
        );

        $attachment = $player->addAttachment($this);
        $permissions = $rank["permissions"] ?? [];

        if(is_array($permissions)){
            foreach($permissions as $permission => $value){
                if(is_int($permission)){
                    $permission = (string) $value;
                    $value = true;
                }

                $permission = trim((string) $permission);

                if($permission !== ""){
                    $attachment->setPermission(
                        $permission,
                        (bool) $value
                    );
                }
            }
        }

        $this->attachments[
            $this->playerKey($player->getName())
        ] = $attachment;

        $player->recalculatePermissions();
    }

    private function removeAttachment(Player $player): void{
        $key = $this->playerKey($player->getName());

        if(!isset($this->attachments[$key])){
            return;
        }

        $player->removeAttachment(
            $this->attachments[$key]
        );

        unset($this->attachments[$key]);
    }

    private function updateAnimatedNameTags(): void{
        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->updateNameTag($player);
        }
    }

    private function updateNameTag(Player $player): void{
        $phase = $this->rainbowPhase();

        $nickname = $this->applyStyle(
            $this->getNickname($player->getName()),
            $this->getSelectedStyle($player->getName()),
            $phase
        );

        $player->setNameTag(
            $this->renderRankPrefix(
                $player->getName(),
                $phase
            ) .
            " " .
            $nickname
        );
    }

    private function renderRankPrefix(
        string $playerName,
        int $phase
    ): string{
        $customRank = (string) $this->getPlayerDataValue(
            $playerName,
            "custom-rank",
            ""
        );

        if(
            $customRank !== "" &&
            $this->canUseCustomRank($playerName)
        ){
            if(str_starts_with(
                strtolower($customRank),
                "rainbow:"
            )){
                if(!$this->isAdministratorRank($playerName)){
                    $rank = $this->getRankConfig(
                        $this->getPlayerRank($playerName)
                    );

                    return $this->translateColors(
                        (string) (
                            $rank["prefix"] ??
                            "§f[§aNoob§f]"
                        )
                    );
                }

                $name = trim(substr($customRank, 8));

                return "§d[" .
                    $this->applyStyle(
                        $name,
                        "rainbow",
                        $phase
                    ) .
                    "§d]";
            }

            return "§d[" .
                $this->translateColors($customRank) .
                "§d]";
        }

        $rank = $this->getRankConfig(
            $this->getPlayerRank($playerName)
        );

        return $this->translateColors(
            (string) (
                $rank["prefix"] ??
                "§f[§aNoob§f]"
            )
        );
    }

    private function applyStyle(
        string $text,
        string $styleId,
        int $phase
    ): string{
        $styles = $this->getColorStyles();
        $style = $styles[$styleId] ?? null;

        $code = is_array($style)
            ? (string) ($style["code"] ?? "§f")
            : "§f";

        if(
            $code === "rainbow" &&
            !$this->isAdministratorRankByStyle($styleId, $text)
        ){
            $code = "§f";
        }

        if($code !== "rainbow"){
            return $code . TextFormat::clean($text);
        }

        $colors = [
            "§c",
            "§6",
            "§e",
            "§a",
            "§b",
            "§9",
            "§d",
            "§5"
        ];

        $characters = mb_str_split(
            TextFormat::clean($text)
        );

        $result = "";

        foreach($characters as $index => $character){
            if($character === " "){
                $result .= " ";
                continue;
            }

            $result .=
                $colors[($index + $phase) % count($colors)] .
                $character;
        }

        return $result;
    }

    private function isAdministratorRankByStyle(
        string $styleId,
        string $text
    ): bool{
        return $styleId === "rainbow";
    }

    private function canUseRankCommand(
        Player $player,
        string $command
    ): bool{
        if($this->isAdministratorRank($player->getName())){
            return true;
        }

        $command = $this->normalizeCommand($command);

        return in_array(
            $command,
            $this->getAllowedCommands($player),
            true
        );
    }

    /**
     * @return string[]
     */
    private function getAllowedCommands(Player $player): array{
        if($this->isAdministratorRank($player->getName())){
            return $this->getAllConfiguredCommands();
        }

        $rank = $this->getRankConfig(
            $this->getPlayerRank($player->getName())
        );

        $commands = $rank["commands"] ?? [];

        if(!is_array($commands)){
            return [];
        }

        $result = [];

        foreach($commands as $command){
            $normalized = $this->normalizeCommand(
                (string) $command
            );

            if($normalized !== ""){
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return string[]
     */
    private function getAllConfiguredCommands(): array{
        $commands = [];

        foreach($this->getOrderedRankIds() as $rankId){
            $rank = $this->getRankConfig($rankId);
            $rankCommands = $rank["commands"] ?? [];

            if(!is_array($rankCommands)){
                continue;
            }

            foreach($rankCommands as $command){
                $command = $this->normalizeCommand(
                    (string) $command
                );

                if($command !== "" && $command !== "*"){
                    $commands[] = $command;
                }
            }
        }

        $commands[] = "rankrename";
        $commands[] = "pets";

        return array_values(array_unique($commands));
    }

    /**
     * @return string[]
     */
    private function getAllowedStyles(string $playerName): array{
        $rank = $this->getRankConfig(
            $this->getPlayerRank($playerName)
        );

        $styles = $rank["colors"] ?? ["white"];

        if(!is_array($styles)){
            $styles = ["white"];
        }

        $result = [];

        foreach($styles as $style){
            $style = strtolower((string) $style);

            if(
                $style === "rainbow" &&
                !$this->isAdministratorRank($playerName)
            ){
                continue;
            }

            if(isset($this->getColorStyles()[$style])){
                $result[] = $style;
            }
        }

        if(count($result) === 0){
            $result[] = "white";
        }

        return array_values(array_unique($result));
    }

    private function getSelectedStyle(string $playerName): string{
        $style = strtolower(
            (string) $this->getPlayerDataValue(
                $playerName,
                "style",
                "white"
            )
        );

        $allowed = $this->getAllowedStyles($playerName);

        return in_array($style, $allowed, true)
            ? $style
            : $allowed[0];
    }

    private function isAdministratorRank(string $playerName): bool{
        $rank = $this->getRankConfig(
            $this->getPlayerRank($playerName)
        );

        return (bool) ($rank["administrator"] ?? false);
    }

    private function canManageRanks(CommandSender $sender): bool{
        if($sender->hasPermission("cuteranks.admin")){
            return true;
        }

        return $sender instanceof Player &&
            $this->isAdministratorRank($sender->getName());
    }

    private function canUseCustomRank(string $playerName): bool{
        return $this->getPlayerRank($playerName) === "special" ||
            $this->isAdministratorRank($playerName);
    }

    private function getRankLevel(string $playerName): int{
        $rankId = $this->getPlayerRank($playerName);
        $order = $this->getOrderedRankIds();
        $level = array_search($rankId, $order, true);

        return $level === false ? 0 : (int) $level;
    }

    private function getNickname(string $playerName): string{
        $nickname = trim(
            (string) $this->getPlayerDataValue(
                $playerName,
                "nickname",
                $playerName
            )
        );

        return $nickname !== ""
            ? $nickname
            : $playerName;
    }

    private function getDefaultRank(): string{
        $rankId = strtolower(
            (string) $this->getConfig()->getNested(
                "settings.default-rank",
                "noob"
            )
        );

        return $this->rankExists($rankId)
            ? $rankId
            : "noob";
    }

    private function rankExists(string $rankId): bool{
        $ranks = $this->getConfig()->get("ranks", []);

        return is_array($ranks) &&
            isset($ranks[$rankId]) &&
            is_array($ranks[$rankId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRankConfig(string $rankId): array{
        $ranks = $this->getConfig()->get("ranks", []);

        $rank = is_array($ranks)
            ? ($ranks[$rankId] ?? [])
            : [];

        return is_array($rank) ? $rank : [];
    }

    private function getRankDisplay(string $rankId): string{
        $rank = $this->getRankConfig($rankId);

        return (string) (
            $rank["display-name"] ??
            ucfirst($rankId)
        );
    }

    /**
     * @return string[]
     */
    private function getOrderedRankIds(): array{
        $order = $this->getConfig()->get(
            "rank-order",
            []
        );

        if(!is_array($order)){
            return [];
        }

        $result = [];

        foreach($order as $rankId){
            $rankId = strtolower((string) $rankId);

            if($this->rankExists($rankId)){
                $result[] = $rankId;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getColorStyles(): array{
        $styles = $this->getConfig()->get(
            "color-styles",
            []
        );

        if(!is_array($styles)){
            return [];
        }

        return array_filter(
            $styles,
            static fn(mixed $style): bool =>
                is_array($style)
        );
    }

    private function getStyleDisplayName(string $styleId): string{
        $styles = $this->getColorStyles();
        $style = $styles[$styleId] ?? null;

        return is_array($style)
            ? (string) ($style["name"] ?? $styleId)
            : $styleId;
    }

    private function getPlayerDataValue(
        string $playerName,
        string $field,
        mixed $default = null
    ): mixed{
        return $this->playerData->getNested(
            "players." .
            $this->playerKey($playerName) .
            "." .
            $field,
            $default
        );
    }

    private function setPlayerDataValue(
        string $playerName,
        string $field,
        mixed $value,
        bool $save = true
    ): void{
        $this->ensurePlayerData($playerName);

        $this->playerData->setNested(
            "players." .
            $this->playerKey($playerName) .
            "." .
            $field,
            $value
        );

        if($save){
            $this->playerData->save();
        }
    }

    private function findOnlinePlayer(string $name): ?Player{
        $name = strtolower(trim($name));

        foreach($this->getServer()->getOnlinePlayers() as $player){
            if(strtolower($player->getName()) === $name){
                return $player;
            }
        }

        return null;
    }

    private function showNotice(
        Player $player,
        string $title,
        string $message,
        bool $backToAdmin = false
    ): void{
        $form = new SimpleForm(
            function(Player $player, int $selected) use (
                $backToAdmin
            ): void{
                if(
                    $backToAdmin &&
                    $this->canManageRanks($player)
                ){
                    $this->showAdminMenu($player);
                }else{
                    $this->showPlayerRankMenu($player);
                }
            }
        );

        $form->setTitle($title);
        $form->setContent($message);
        $form->addButton("§dตกลง §f♡");

        $player->sendForm($form);
    }

    private function normalizeCommand(string $command): string{
        $command = strtolower(
            ltrim(trim($command), "/")
        );

        return match($command){
            "nickname" => "nick",
            "rn" => "rankrename",
            "rc" => "rankcmd",
            "pet" => "pets",
            default => $command
        };
    }

    private function filterCustomColorText(string $text): string{
        $text = preg_replace(
            "/&(?![0-9a-fk-or])/i",
            "",
            $text
        ) ?? $text;

        return preg_replace(
            "/§(?![0-9a-fk-or])/i",
            "",
            $text
        ) ?? $text;
    }

    private function translateColors(string $text): string{
        return preg_replace(
            "/&([0-9a-fk-or])/i",
            "§$1",
            $text
        ) ?? $text;
    }

    private function rainbowPhase(): int{
        return ((int) floor(microtime(true) * 2)) % 8;
    }

    private function playerKey(string $playerName): string{
        return strtolower(trim($playerName));
    }

    private function prefix(): string{
        return $this->translateColors(
            (string) $this->getConfig()->getNested(
                "settings.prefix",
                "§l§dCute§bRanks §r§f♡ "
            )
        );
    }
}