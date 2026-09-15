<?php

declare(strict_types=1);

namespace MyServer\SimpleMoney;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;

final class Main extends PluginBase implements Listener{

	private EconomyService $economy;
	private BankService $bank;
	private ShopService $shop;
	private MoneyFormat $formatter;

	/** @var array<string, mixed> */
	private array $settings = [];

	protected function onEnable() : void{
		@mkdir($this->getDataFolder(), 0777, true);

		$this->settings = $this->loadSettings();
		$this->formatter = new MoneyFormat(
			(array) ($this->settings["money-format"] ?? [])
		);

		$this->economy = new EconomyService(
			$this->getDataFolder() . "money-data.json",
			(float) ($this->settings["starting-balance"] ?? 1500.0)
		);

		$this->bank = new BankService($this, $this->economy);
		$this->shop = new ShopService($this, $this->economy);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->registerAutoSaveTask();
		$this->registerAutoIncomeTask();

		// BossBar Update Task
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			foreach($this->getServer()->getOnlinePlayers() as $player){
				$this->updateBossBar($player);
			}
		}), 40);

		$this->getLogger()->info("§d✦ SimpleMoney v4 พร้อมใช้งานแล้ว ✦");
	}

	protected function onDisable() : void{
		if(isset($this->economy)){
			$this->economy->save();
		}

		if(isset($this->bank) && method_exists($this->bank, "save")){
			$this->bank->save();
		}
	}


	// --- BossBar System ---
	private function getBossBarId(Player $player) : int{
		return $player->getId() + 1000000;
	}

	public function updateBossBar(Player $player) : void{
		$pk = new BossEventPacket();
		$pk->bossActorUniqueId = $this->getBossBarId($player);
		$pk->eventType = BossEventPacket::TYPE_TITLE;
		$pk->title = "§d§lชื่อ : §f" . $player->getName() . " §f| §cเงิน : §e" . $this->moneyText($this->economy->getMoney($player));
		$player->getNetworkSession()->sendDataPacket($pk);
	}

	public function showBossBar(Player $player) : void{
		$pk = new BossEventPacket();
		$pk->bossActorUniqueId = $this->getBossBarId($player);
		$pk->eventType = BossEventPacket::TYPE_SHOW;
		$pk->title = "§d§lชื่อ : §f" . $player->getName() . " §f| §cเงิน : §e" . $this->moneyText($this->economy->getMoney($player));
		$pk->healthPercent = 1.0;
		$pk->color = BossBarColor::PURPLE;
		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/*
	 * =====================================================
	 * Tasks
	 * =====================================================
	 */

	private function registerAutoSaveTask() : void{
		$ticks = max(200, (int) ($this->settings["autosave-ticks"] ?? 600));

		$this->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(function() : void{
				$this->economy->save();

				if(method_exists($this->bank, "save")){
					$this->bank->save();
				}
			}),
			$ticks
		);
	}

	private function registerAutoIncomeTask() : void{
		$config = (array) ($this->settings["auto-income"] ?? []);

		if(($config["enabled"] ?? true) !== true){
			return;
		}

		$minutes = max(1.0, (float) ($config["interval-minutes"] ?? 20));
		$amount = max(0.0, (float) ($config["amount"] ?? 20));
		$ticks = (int) round($minutes * 60 * 20);

		if($amount <= 0){
			return;
		}

		$this->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(function() use ($amount, $config, $minutes) : void{
				$onlyOnline = ($config["only-online"] ?? true) === true;

				foreach($this->getServer()->getOnlinePlayers() as $player){
					if($onlyOnline && !$player->isOnline()){
						continue;
					}

					if(!$this->economy->depositAutoIncome($player, $amount)){
						continue;
					}

					$message = (string) (
						$config["message"] ??
						"§l§a💰 §fรายได้อัตโนมัติ §a+{amount}"
					);

					$player->sendMessage(
						str_replace(
							["{amount}", "{balance}", "{interval}"],
							[
								$this->formatMoney($amount),
								$this->formatMoney($this->economy->getMoney($player)),
								(string) $minutes
							],
							$message
						)
					);
				}
			}),
			max(20, $ticks)
		);
	}

	/*
	 * =====================================================
	 * Public API
	 * =====================================================
	 */

	public function getEconomy() : EconomyService{
		return $this->economy;
	}

	public function getBank() : BankService{
		return $this->bank;
	}

	public function getShop() : ShopService{
		return $this->shop;
	}

	public function getFormatter() : MoneyFormat{
		return $this->formatter;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSettings() : array{
		return $this->settings;
	}

	public function formatMoney(float $amount) : string{
		return $this->formatter->format($amount);
	}

	public function moneyText(float $amount) : string{
		return "§e" . $this->formatMoney($amount) . " §6⛃";
	}

	/*
	 * =====================================================
	 * Events
	 * =====================================================
	 */

	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();

		$isNew = $this->economy->ensureAccount($player);

		if(method_exists($this->bank, "ensureAccount")){
			$this->bank->ensureAccount($player);
		}

		if($isNew){
			$player->sendMessage(
				"§l§d✦ §fยินดีต้อนรับ §d" . $player->getName() . " §fค่ะ 💖\n" .
				"§r§fเงินเริ่มต้น: " .
				$this->moneyText($this->economy->getMoney($player))
			);
		}

		$this->showBossBar($player);
	}

	/*
	 * =====================================================
	 * Commands
	 * =====================================================
	 */

	public function onCommand(
		CommandSender $sender,
		Command $command,
		string $label,
		array $args
	) : bool{
		return match($command->getName()){
			"menu" => $this->commandMenu($sender),
			"money" => $this->commandMoney($sender, $args),
			"pay" => $this->commandPay($sender, $args),
			"bank" => $this->commandBank($sender),
			"topmoney" => $this->commandTopMoney($sender),
			"moneyadmin" => $this->commandAdmin($sender, $args),
			"shop" => $this->commandShop($sender),
			"shopadmin" => $this->commandShopAdmin($sender),
			default => false
		};
	}

	private function commandMenu(CommandSender $sender) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		$this->openMainMenu($sender);
		return true;
	}

	private function commandMoney(CommandSender $sender, array $args) : bool{
		if(count($args) === 0){
			if(!$sender instanceof Player){
				$sender->sendMessage("§eใช้: /money <player>");
				return true;
			}

			$sender->sendMessage(
				"§l§d💖 §fเงินติดตัว: " .
				$this->moneyText($this->economy->getMoney($sender)) . "\n" .
				"§l§b🏦 §fเงินธนาคาร: " .
				$this->moneyText($this->bank->getBalance($sender))
			);
			return true;
		}

		if(
			!$sender->hasPermission("simplemoney.money.others") &&
			!$sender->hasPermission("simplemoney.balance.others")
		){
			$sender->sendMessage("§cคุณไม่มีสิทธิ์ดูเงินผู้เล่นอื่น");
			return true;
		}

		$uuid = $this->economy->getKnownUuidByName((string) $args[0]);

		if($uuid === null){
			$sender->sendMessage("§cไม่พบบัญชีของผู้เล่นนี้");
			return true;
		}

		$sender->sendMessage(
			"§l§e✦ §fเงินของ §d" .
			$this->economy->getPlayerName($uuid) . "§f: " .
			$this->moneyText($this->economy->getMoney($uuid))
		);
		return true;
	}

	private function commandPay(CommandSender $sender, array $args) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		if(count($args) !== 2){
			$sender->sendMessage("§eใช้: /pay <player> <amount>");
			return true;
		}

		$target = $this->getServer()->getPlayerExact((string) $args[0]);
		$amount = $this->parseAmount((string) $args[1]);

		if(!$target instanceof Player){
			$sender->sendMessage("§l§c✘ §fไม่พบผู้เล่น หรือออฟไลน์อยู่ค่ะ");
			return true;
		}

		if($amount === null){
			$sender->sendMessage("§l§c✘ §fจำนวนเงินไม่ถูกต้องค่ะ");
			return true;
		}

		if($target->getUniqueId()->equals($sender->getUniqueId())){
			$sender->sendMessage("§l§c✘ §fโอนเงินให้ตัวเองไม่ได้นะคะ");
			return true;
		}

		if(!$this->economy->transfer($sender, $target, $amount, "player-pay")){
			$sender->sendMessage("§l§c✘ §fโอนไม่สำเร็จ เงินอาจไม่พอค่ะ");
			return true;
		}

		$sender->sendMessage(
			"§l§a✔ §fโอน " . $this->moneyText($amount) .
			" §fให้ §d" . $target->getName() . " §fแล้ว 💖"
		);

		$target->sendMessage(
			"§l§a🎁 §fได้รับ " . $this->moneyText($amount) .
			" §fจาก §d" . $sender->getName()
		);
		return true;
	}

	private function commandBank(CommandSender $sender) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		$this->openBankMenu($sender);
		return true;
	}

	private function commandTopMoney(CommandSender $sender) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		$this->openTopMenu($sender);
		return true;
	}

	private function commandShop(CommandSender $sender) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		$this->shop->open($sender);
		return true;
	}

	private function commandShopAdmin(CommandSender $sender) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cใช้คำสั่งนี้ภายในเกมเท่านั้น");
			return true;
		}

		$this->shop->openAdmin($sender);
		return true;
	}

	private function commandAdmin(CommandSender $sender, array $args) : bool{
		if(count($args) === 0 && $sender instanceof Player){
			$this->openAdminMoneyMenu($sender);
			return true;
		}

		if(count($args) !== 3){
			$sender->sendMessage("§eใช้: /moneyadmin <set|add|take> <player> <amount>");
			return true;
		}

		$action = strtolower((string) $args[0]);
		$uuid = $this->economy->getKnownUuidByName((string) $args[1]);
		$amount = $this->parseAmount((string) $args[2], true);

		if($uuid === null){
			$sender->sendMessage("§cไม่พบบัญชีผู้เล่นนี้");
			return true;
		}

		if($amount === null){
			$sender->sendMessage("§cจำนวนเงินไม่ถูกต้อง");
			return true;
		}

		$success = match($action){
			"set" => $this->economy->setMoney($uuid, $amount, "admin-set"),
			"add" => $amount > 0 && $this->economy->deposit($uuid, $amount, "admin-add"),
			"take" => $amount > 0 && $this->economy->withdraw($uuid, $amount, "admin-take"),
			default => null
		};

		if($success === null){
			$sender->sendMessage("§eใช้: /moneyadmin <set|add|take> <player> <amount>");
			return true;
		}

		$sender->sendMessage(
			$success
				? "§l§a✔ §fยอดใหม่ของ §d" .
					$this->economy->getPlayerName($uuid) . "§f: " .
					$this->moneyText($this->economy->getMoney($uuid))
				: "§l§c✘ §fทำรายการไม่สำเร็จ"
		);
		return true;
	}

	/*
	 * =====================================================
	 * UI
	 * =====================================================
	 */

	private function openMainMenu(Player $player) : void{
		$isAdmin = $player->hasPermission("simplemoney.admin");

		$form = new ShopSimpleForm(
			"§l§d✦ §fเมนูการเงิน §d✦",
			"§r§fสวัสดีค่ะ §d" . $player->getName() . " §f💖\n\n" .
			"§l§6✦ §fเงินติดตัว: §r" .
			$this->moneyText($this->economy->getMoney($player)) . "\n" .
			"§l§b✦ §fเงินธนาคาร: §r" .
			$this->moneyText($this->bank->getBalance($player)) . "\n\n" .
			"§fเลือกเมนูที่ต้องการค่ะ ✨",
			function(Player $player, int $button) use ($isAdmin) : void{
				match($button){
					0 => $this->openWalletMenu($player),
					1 => $this->openBankMenu($player),
					2 => $this->shop->open($player),
					3 => $this->openTopMenu($player),
					4 => $isAdmin ? $this->openAdminMoneyMenu($player) : null,
					default => null
				};
			}
		);

		$form->addButton("§l§e💰 เงินติดตัว\n§r§fโอนเงินให้เพื่อน", "textures/items/gold_ingot");
		$form->addButton("§l§b🏦 ธนาคาร\n§r§fฝาก • ถอน", "textures/blocks/chest_front");
		$form->addButton("§l§a🛒 ร้านค้า\n§r§fซื้อ • ขาย", "textures/items/emerald");
		$form->addButton("§l§6🏆 อันดับเงิน\n§r§fTop Money • Top Bank", "textures/items/diamond");

		if($isAdmin){
			$form->addButton("§l§5⚙ แอดมินการเงิน\n§r§fset • add • take", "textures/items/nether_star");
		}

		$player->sendForm($form);
	}

	private function openWalletMenu(Player $player) : void{
		$form = new ShopCustomForm(
			"§l§d💖 กระเป๋าเงิน",
			function(Player $player, array $data) : void{
				$targetName = trim((string) ($data[1] ?? ""));
				$amount = $this->parseAmount((string) ($data[2] ?? ""));

				if($targetName === ""){
					return;
				}

				$target = $this->getServer()->getPlayerExact($targetName);

				if(!$target instanceof Player || $amount === null){
					$player->sendMessage("§l§c✘ §fข้อมูลไม่ถูกต้องค่ะ");
					return;
				}

				if($target->getUniqueId()->equals($player->getUniqueId())){
					$player->sendMessage("§l§c✘ §fโอนให้ตัวเองไม่ได้นะคะ");
					return;
				}

				if(!$this->economy->transfer($player, $target, $amount, "form-pay")){
					$player->sendMessage("§l§c✘ §fเงินไม่พอค่ะ");
					return;
				}

				$player->sendMessage(
					"§l§a✔ §fโอน " . $this->moneyText($amount) .
					" §fให้ §d" . $target->getName() . " §fแล้ว 💖"
				);

				$target->sendMessage(
					"§l§a🎁 §fได้รับ " . $this->moneyText($amount) .
					" §fจาก §d" . $player->getName()
				);
			}
		);

		$form->addLabel(
			"§fยอดคงเหลือ: " .
			$this->moneyText($this->economy->getMoney($player)) .
			"\n\n§fกรอกข้อมูลเพื่อโอนเงินค่ะ ✨"
		);

		$form->addInput("§dชื่อผู้เล่นปลายทาง", "เช่น Steve");
		$form->addInput("§eจำนวนเงิน", "เช่น 100");

		$player->sendForm($form);
	}

	private function openBankMenu(Player $player) : void{
		$form = new ShopSimpleForm(
			"§l§b🏦 ธนาคารน้องเมฆ ☁",
			"§r§l§e💰 §fเงินติดตัว: §r" .
			$this->moneyText($this->economy->getMoney($player)) . "\n" .
			"§l§b🏦 §fเงินฝาก: §r" .
			$this->moneyText($this->bank->getBalance($player)) . "\n\n" .
			"§fฝากเงินไว้เพื่อความปลอดภัยค่ะ ✨",
			function(Player $player, int $button) : void{
				match($button){
					0 => $this->openBankAmountForm($player, true),
					1 => $this->openBankAmountForm($player, false),
					2 => $this->openMainMenu($player),
					default => null
				};
			}
		);

		$form->addButton("§l§a📥 ฝากเงิน\n§r§fWallet → Bank");
		$form->addButton("§l§e📤 ถอนเงิน\n§r§fBank → Wallet");
		$form->addButton("§l§c↩ กลับเมนูหลัก");

		$player->sendForm($form);
	}

	private function openBankAmountForm(Player $player, bool $isDeposit) : void{
		$available = $isDeposit
			? $this->economy->getMoney($player)
			: $this->bank->getBalance($player);

		$form = new ShopCustomForm(
			$isDeposit ? "§l§a📥 ฝากเงิน" : "§l§e📤 ถอนเงิน",
			function(Player $player, array $data) use ($isDeposit) : void{
				$amount = $this->parseAmount((string) ($data[1] ?? ""));

				if($amount === null){
					$player->sendMessage("§l§c✘ §fจำนวนเงินไม่ถูกต้องค่ะ");
					return;
				}

				$success = $isDeposit
					? $this->bank->deposit($player, $amount, "form-bank-deposit")
					: $this->bank->withdraw($player, $amount, "form-bank-withdraw");

				$player->sendMessage(
					$success
						? "§l§a✔ §f" . ($isDeposit ? "ฝาก" : "ถอน") . "เงิน " .
							$this->moneyText($amount) . " §fสำเร็จค่ะ ✨"
						: "§l§c✘ §fทำรายการไม่สำเร็จ ยอดเงินอาจไม่พอค่ะ"
				);

				if($success){
					$this->openBankMenu($player);
				}
			}
		);

		$form->addLabel(
			"§fยอดที่ใช้ได้: " . $this->moneyText($available) .
			"\n\n§fกรอกจำนวนเงินที่ต้องการค่ะ"
		);

		$form->addInput("§dจำนวนเงิน", "เช่น 100");

		$player->sendForm($form);
	}

	private function openTopMenu(Player $player) : void{
		$form = new ShopSimpleForm(
			"§l§6🏆 อันดับผู้เล่น",
			"§r§fมาดูผู้เล่นที่ร่ำรวยที่สุดกันค่ะ ✨",
			function(Player $player, int $button) : void{
				match($button){
					0 => $this->openTopList($player, false),
					1 => $this->openTopList($player, true),
					2 => $this->openMainMenu($player),
					default => null
				};
			}
		);

		$form->addButton("§l§e🥇 Top Money\n§r§fอันดับเงินติดตัว");
		$form->addButton("§l§b🏦 Top Bank\n§r§fอันดับเงินธนาคาร");
		$form->addButton("§l§c↩ กลับเมนูหลัก");

		$player->sendForm($form);
	}

	private function openTopList(Player $player, bool $isBank) : void{
		$limit = max(1, (int) ($this->settings["top-limit"] ?? 10));

		$rows = $isBank
			? $this->bank->getTop($limit)
			: $this->economy->getTop($limit);

		$content = "§f━━━━━━━━━━━━━━━━\n";

		foreach($rows as $index => $row){
			$rank = $index + 1;

			$medal = match($rank){
				1 => "§6🥇",
				2 => "§f🥈",
				3 => "§c🥉",
				default => "§d✦"
			};

			$content .= $medal . " §f#" . $rank . " §d" .
				(string) ($row["name"] ?? "-") . " §f• §e" .
				$this->formatMoney((float) ($row["balance"] ?? 0)) . " §6⛃\n";
		}

		if(count($rows) === 0){
			$content .= "§fยังไม่มีข้อมูลอันดับค่ะ\n";
		}

		$content .= "§f━━━━━━━━━━━━━━━━";

		$form = new ShopSimpleForm(
			$isBank ? "§l§b🏦 Top Bank" : "§l§e🥇 Top Money",
			$content,
			function(Player $player, int $button) : void{
				$this->openTopMenu($player);
			}
		);

		$form->addButton("§l§c↩ กลับ");

		$player->sendForm($form);
	}

	private function openAdminMoneyMenu(Player $player) : void{
		if(!$player->hasPermission("simplemoney.admin")){
			$player->sendMessage("§l§c✘ §fคุณไม่มีสิทธิ์ใช้งานเมนูนี้ค่ะ");
			return;
		}

		$form = new ShopCustomForm(
			"§l§5⚙ แอดมินการเงิน",
			function(Player $player, array $data) : void{
				$mode = (int) ($data[1] ?? 0);
				$targetName = trim((string) ($data[2] ?? ""));
				$amount = $this->parseAmount((string) ($data[3] ?? ""), true);

				$uuid = $this->economy->getKnownUuidByName($targetName);

				if($uuid === null || $amount === null){
					$player->sendMessage("§l§c✘ §fข้อมูลไม่ถูกต้องค่ะ");
					return;
				}

				$success = match($mode){
					0 => $this->economy->setMoney($uuid, $amount, "admin-set"),
					1 => $amount > 0 && $this->economy->deposit($uuid, $amount, "admin-add"),
					2 => $amount > 0 && $this->economy->withdraw($uuid, $amount, "admin-take"),
					default => false
				};

				$player->sendMessage(
					$success
						? "§l§a✔ §fยอดใหม่ของ §d" .
							$this->economy->getPlayerName($uuid) . "§f: " .
							$this->moneyText($this->economy->getMoney($uuid))
						: "§l§c✘ §fทำรายการไม่สำเร็จค่ะ"
				);
			}
		);

		$form->addLabel("§fจัดการเงินผู้เล่นที่เคยเข้าเซิร์ฟเวอร์แล้ว");

		$form->addDropdown("§bโหมดการทำงาน", [
			"§eตั้งค่าเงิน (set)",
			"§aเพิ่มเงิน (add)",
			"§cหักเงิน (take)"
		]);

		$form->addInput("§dชื่อผู้เล่น", "เช่น Steve");
		$form->addInput("§eจำนวนเงิน", "เช่น 1000");

		$player->sendForm($form);
	}

	/*
	 * =====================================================
	 * Helpers
	 * =====================================================
	 */

	private function parseAmount(string $input, bool $allowZero = false) : ?float{
		$input = str_replace(",", "", trim($input));

		if(!is_numeric($input)){
			return null;
		}

		$amount = round((float) $input, 2);

		if(!is_finite($amount)){
			return null;
		}

		if($allowZero){
			return $amount >= 0 ? $amount : null;
		}

		return $amount > 0 ? $amount : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadSettings() : array{
		$defaults = [
			"starting-balance" => 1500.0,
			"autosave-ticks" => 600,
			"top-limit" => 10,
			"interest-rate-percent" => 1.0,
			"interest-interval-ticks" => 72000,

			"auto-income" => [
				"enabled" => true,
				"amount" => 20.0,
				"interval-minutes" => 20,
				"only-online" => true,
				"message" => "§l§a💰 §fรายได้ประจำ §a+{amount}§6 ⛃ §fทุก {interval} นาที §8• §fยอดเงิน §e{balance}§6 ⛃"
			],

			"money-format" => [
				"enabled" => true,
				"decimals" => 2,
				"plain-below" => 10000,
				"suffixes" => [
					"", "K", "M", "B", "T",
					"Qa", "Qi", "Sx", "Sp",
					"Oc", "No", "Dc"
				]
			],
		];

		$file = $this->getDataFolder() . "config.json";

		if(!is_file($file)){
			file_put_contents(
				$file,
				json_encode(
					$defaults,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);

			return $defaults;
		}

		try{
			$raw = file_get_contents($file);

			if($raw === false){
				return $defaults;
			}

			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

			if(!is_array($data)){
				return $defaults;
			}

			return array_replace_recursive($defaults, $data);
		}catch(\Throwable){
			$this->getLogger()->warning("config.json ไม่ถูกต้อง ใช้ค่าเริ่มต้นแทน");
			return $defaults;
		}
	}
}

/*
 * =========================================================
 * ระบบย่อจำนวนเงิน
 * =========================================================
 */

final class MoneyFormat{

	/** @param array<string, mixed> $settings */
	public function __construct(
		private array $settings
	){}

	public function format(float $amount) : string{
		$enabled = ($this->settings["enabled"] ?? true) === true;
		$negative = $amount < 0;
		$value = abs($amount);

		$plainBelow = (float) ($this->settings["plain-below"] ?? 10000);

		if(!$enabled || $value < $plainBelow){
			$decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

			return ($negative ? "-" : "") . number_format($value, $decimals);
		}

		$suffixes = $this->settings["suffixes"] ?? [];

		if(!is_array($suffixes) || count($suffixes) === 0){
			$suffixes = ["", "K", "M", "B", "T", "Qa", "Qi", "Sx", "Sp", "Oc", "No", "Dc"];
		}

		$suffixes = array_values(array_map("strval", $suffixes));
		$index = 0;
		$last = count($suffixes) - 1;

		while($value >= 1000 && $index < $last){
			$value /= 1000;
			$index++;
		}

		$decimals = max(0, min(4, (int) ($this->settings["decimals"] ?? 2)));
		$text = number_format($value, $decimals, ".", "");

		if(str_contains($text, ".")){
			$text = rtrim(rtrim($text, "0"), ".");
		}

		return ($negative ? "-" : "") . $text . $suffixes[$index];
	}

	public function plain(float $amount) : string{
		return number_format($amount, 2);
	}
}

