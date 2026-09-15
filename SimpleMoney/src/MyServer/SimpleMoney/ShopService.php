<?php

declare(strict_types=1);

namespace MyServer\SimpleMoney;

use pocketmine\form\Form;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\StringToItemParser;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\StringTag;
use pocketmine\player\Player;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class ShopService{

	public const MODE_BUY = "buy";
	public const MODE_SELL = "sell";

	/** @var array<int, array<string, mixed>> */
	private array $categories = [];

	/** @var array<int, array<string, mixed>> */
	private array $products = [];

	private string $dataFile;

	/** @var array<string, array<int, string>> */
	private array $resourcePackTextureIndex = [];

	public function __construct(
		private Main $plugin,
		private EconomyService $economy
	){
		$this->dataFile = $this->plugin->getDataFolder() . "shops.json";
		$this->loadResourcePackTextureIndex();
		$this->load();
	}

	/*
	 * =====================================================
	 * Public API
	 * =====================================================
	 */

	public function open(Player $player) : void{
		$this->openShopHome($player);
	}

	public function openAdmin(Player $player) : void{
		if(!$player->hasPermission("simplemoney.shopadmin")){
			$player->sendMessage($this->error("คุณไม่มีสิทธิ์จัดการร้านค้าค่ะ"));
			return;
		}

		$this->openAdminHome($player);
	}

	/** @return array<int, array<string, mixed>> */
	public function getProducts() : array{
		return array_values($this->products);
	}

	/** @return array<int, array<string, mixed>> */
	public function getItems() : array{
		return $this->getProducts();
	}

	/** @return array<string, mixed>|null */
	public function getProduct(string $productId) : ?array{
		foreach($this->products as $product){
			if((string) ($product["id"] ?? "") === $productId){
				return $product;
			}
		}

		return null;
	}

	/** @return array<string, mixed>|null */
	public function getItem(string $productId) : ?array{
		return $this->getProduct($productId);
	}

	/** @return array<int, array<string, mixed>> */
	public function getCategories() : array{
		return array_values($this->categories);
	}

	/** @return array<string, mixed>|null */
	public function getCategory(string $categoryId) : ?array{
		foreach($this->categories as $category){
			if((string) ($category["id"] ?? "") === $categoryId){
				return $category;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getProductsByCategory(
		string $categoryId,
		?string $mode = null
	) : array{
		return array_values(array_filter(
			$this->products,
			static function(array $product) use ($categoryId, $mode) : bool{
				if((string) ($product["category"] ?? "") !== $categoryId){
					return false;
				}

				if($mode === self::MODE_BUY){
					return (float) ($product["buy"] ?? 0) > 0;
				}

				if($mode === self::MODE_SELL){
					return (float) ($product["sell"] ?? 0) > 0;
				}

				return true;
			}
		));
	}

	/*
	 * =====================================================
	 * หน้าร้านค้า
	 * =====================================================
	 */

	private function openShopHome(Player $player) : void{
		$isAdmin = $player->hasPermission("simplemoney.shopadmin");

		$form = new ShopSimpleForm(
			"§l§d🌸 ร้านค้าน้องแมว 🌸",
			"§r§fสวัสดีค่ะ §d" . $player->getName() . " §f💖\n\n" .
			"§fเงินของคุณ: " . $this->money($this->economy->getMoney($player)) . "\n\n" .
			"§a🛒 โซนซื้อ §f• ซื้อไอเทมจากร้าน\n" .
			"§c📦 โซนขาย §f• ขายไอเทมให้ร้าน\n\n" .
			"§fเลือกโซนที่ต้องการได้เลยค่ะ ✨",
			function(Player $player, int $button) use ($isAdmin) : void{
				match($button){
					0 => $this->openCategoryList($player, self::MODE_BUY),
					1 => $this->openCategoryList($player, self::MODE_SELL),
					2 => $isAdmin ? $this->openAdminHome($player) : null,
					default => null
				};
			}
		);

		$form->addButton(
			"§l§a🛒 โซนซื้อสินค้า\n§r§fเลือกซื้อไอเทมจากร้าน",
			"textures/items/emerald"
		);

		$form->addButton(
			"§l§c📦 โซนขายสินค้า\n§r§fนำไอเทมมาขายให้ร้าน",
			"textures/items/gold_ingot"
		);


		if($isAdmin){
			$form->addButton(
				"§l§5⚙ จัดการร้านค้า\n§r§fสำหรับแอดมิน",
				"textures/items/nether_star"
			);
		}

		$player->sendForm($form);
	}

	private function openCategoryList(Player $player, string $mode) : void{
		$isBuy = $mode === self::MODE_BUY;

		$categories = array_values(array_filter(
			$this->categories,
			function(array $category) use ($mode) : bool{
				return count(
					$this->getProductsByCategory((string) $category["id"], $mode)
				) > 0;
			}
		));

		$form = new ShopSimpleForm(
			$isBuy ? "§l§a🛒 โซนซื้อสินค้า" : "§l§c📦 โซนขายสินค้า",
			"§r§fเงินของคุณ: " . $this->money($this->economy->getMoney($player)) . "\n\n" .
			(
				count($categories) > 0
					? ($isBuy
						? "§fเลือกหมวดสินค้าที่ต้องการซื้อค่ะ ✨"
						: "§fเลือกหมวดไอเทมที่ต้องการขายค่ะ ✨")
					: "§eยังไม่มีสินค้าในโซนนี้ค่ะ"
			),
			function(Player $player, int $button) use ($categories, $mode) : void{
				if(isset($categories[$button])){
					$this->openProductList(
						$player,
						(string) $categories[$button]["id"],
						$mode
					);
					return;
				}

				$this->openShopHome($player);
			}
		);

		foreach($categories as $category){
			$count = count(
				$this->getProductsByCategory((string) $category["id"], $mode)
			);

			$form->addButton(
				"§l§f" . (string) ($category["emoji"] ?? "✨") .
				" " . (string) $category["name"] . "\n" .
				"§r§fมีสินค้า §e" . $count . " §fรายการ",
				$this->resolveIcon(trim((string) ($category["icon"] ?? "")))
			);
		}

		$form->addButton("§l§c↩ กลับหน้าร้านค้า");

		$player->sendForm($form);
	}

	private function openProductList(
		Player $player,
		string $categoryId,
		string $mode
	) : void{
		$isBuy = $mode === self::MODE_BUY;
		$category = $this->getCategory($categoryId);

		if($category === null){
			$this->openCategoryList($player, $mode);
			return;
		}

		$products = $this->getProductsByCategory($categoryId, $mode);

		$form = new ShopSimpleForm(
			"§l" . ($isBuy ? "§a🛒 " : "§c📦 ") .
			(string) ($category["emoji"] ?? "✨") . " " .
			(string) $category["name"],
			"§r§fเงินของคุณ: " . $this->money($this->economy->getMoney($player)) . "\n\n" .
			($isBuy
				? "§fกดที่สินค้าเพื่อซื้อค่ะ"
				: "§fกดที่ไอเทมเพื่อขายให้ร้านค่ะ"),
			function(Player $player, int $button) use ($products, $mode) : void{
				if(isset($products[$button])){
					$this->openProductDetail(
						$player,
						(string) $products[$button]["id"],
						$mode
					);
					return;
				}

				$this->openCategoryList($player, $mode);
			}
		);

		foreach($products as $product){
			$price = $isBuy
				? (float) ($product["buy"] ?? 0)
				: (float) ($product["sell"] ?? 0);

			$form->addButton(
				"§l§f" . (string) ($product["name"] ?? "Unknown") .
				" §fx" . max(1, (int) ($product["amount"] ?? 1)) . "\n" .
				"§r" . ($isBuy ? "§aราคาซื้อ " : "§cราคาขาย ") .
				"§e" . number_format($price, 2) . " §6⛃",
				$this->resolveIcon(trim((string) ($product["icon"] ?? "")), $this->createItem($product, 1))
			);
		}

		$form->addButton("§l§c↩ กลับเลือกหมวดหมู่");

		$player->sendForm($form);
	}

	private function openProductDetail(
		Player $player,
		string $productId,
		string $mode
	) : void{
		$product = $this->getProduct($productId);

		if($product === null){
			$player->sendMessage($this->error("ไม่พบสินค้านี้ค่ะ"));
			return;
		}

		$isBuy = $mode === self::MODE_BUY;
		$amount = max(1, (int) ($product["amount"] ?? 1));

		$price = $isBuy
			? (float) ($product["buy"] ?? 0)
			: (float) ($product["sell"] ?? 0);

		$category = $this->getCategory((string) ($product["category"] ?? ""));

		$form = new ShopCustomForm(
			"§l" . ($isBuy ? "§a🛒 ซื้อ " : "§c📦 ขาย ") .
			(string) ($product["name"] ?? ""),
			function(Player $player, array $data) use ($productId, $mode, $product) : void{
				$sets = $this->parseInteger((string) ($data[1] ?? "1"));

				if($sets === null){
					$player->sendMessage($this->error("จำนวนชุดไม่ถูกต้องค่ะ"));
					return;
				}

				if($mode === self::MODE_BUY){
					$this->buy($player, $productId, $sets);
				}else{
					$this->sell($player, $productId, $sets);
				}

				$this->openProductList(
					$player,
					(string) ($product["category"] ?? ""),
					$mode
				);
			}
		);

		$form->addLabel(
			"§fหมวดหมู่: §b" .
			($category !== null ? (string) $category["name"] : "-") . "\n" .
			"§fจำนวนต่อชุด: §e" . $amount . "\n" .
			($isBuy ? "§aราคาซื้อต่อชุด: §e" : "§cราคาขายต่อชุด: §e") .
			number_format($price, 2) . " §6⛃\n\n" .
			"§fเงินของคุณ: " . $this->money($this->economy->getMoney($player)) .
			"\n\n§fระบุจำนวนชุดที่ต้องการค่ะ"
		);

		$form->addInput("§dจำนวนชุด", "1", "1");

		$player->sendForm($form);
	}

	/*
	 * =====================================================
	 * ระบบซื้อและขาย
	 * =====================================================
	 */

	public function buy(Player $player, string $productId, int $sets = 1) : bool{
		$product = $this->getProduct($productId);

		if($product === null || $sets < 1){
			$player->sendMessage($this->error("ไม่พบสินค้านี้ค่ะ"));
			return false;
		}

		$unitPrice = round((float) ($product["buy"] ?? 0), 2);

		if(!is_finite($unitPrice) || $unitPrice <= 0){
			$player->sendMessage($this->error("สินค้านี้ไม่เปิดขายค่ะ"));
			return false;
		}

		$totalPrice = round($unitPrice * $sets, 2);
		$item = $this->createItem($product, $sets);

		if($item === null || $item->isNull()){
			$player->sendMessage($this->error("ข้อมูลไอเทมไม่ถูกต้องค่ะ"));
			return false;
		}

		if($this->economy->getMoney($player) < $totalPrice){
			$player->sendMessage(
				$this->error("เงินไม่พอค่ะ ต้องใช้ " . $this->money($totalPrice))
			);
			return false;
		}

		if(!$this->economy->withdraw($player, $totalPrice, "shop-buy:" . $productId)){
			$player->sendMessage($this->error("หักเงินไม่สำเร็จค่ะ"));
			return false;
		}

		$inventory = $player->getInventory();
		$delivered = 0;
		$dropped = 0;

		foreach($this->splitToStacks($item) as $stack){
			$before = $stack->getCount();
			$leftovers = $inventory->addItem($stack);

			$remaining = 0;

			foreach($leftovers as $leftover){
				$remaining += $leftover->getCount();

				$player->getWorld()->dropItem(
					$player->getPosition()->add(0, 0.5, 0),
					$leftover
				);
			}

			$delivered += $before - $remaining;
			$dropped += $remaining;
		}

		if($delivered <= 0 && $dropped <= 0){
			$this->economy->deposit($player, $totalPrice, "shop-refund:" . $productId);
			$player->sendMessage($this->error("ส่งไอเทมไม่สำเร็จ คืนเงินให้แล้วค่ะ"));
			return false;
		}

		$player->sendMessage(
			"§l§a🛒 §fซื้อ §d" . (string) $product["name"] .
			" §fx" . ($delivered + $dropped) .
			" §fจ่าย " . $this->money($totalPrice)
		);

		if($dropped > 0){
			$player->sendMessage(
				"§l§e⚠ §fกระเป๋าเต็ม §e" . $dropped . " §fชิ้นถูกวางไว้ที่พื้นค่ะ 📦"
			);
		}

		return true;
	}

	public function sell(Player $player, string $productId, int $sets = 1) : bool{
		$product = $this->getProduct($productId);

		if($product === null || $sets < 1){
			$player->sendMessage($this->error("ไม่พบสินค้านี้ค่ะ"));
			return false;
		}

		$unitPrice = round((float) ($product["sell"] ?? 0), 2);

		if(!is_finite($unitPrice) || $unitPrice <= 0){
			$player->sendMessage($this->error("ร้านไม่รับซื้อไอเทมนี้ค่ะ"));
			return false;
		}

		$totalPrice = round($unitPrice * $sets, 2);
		$item = $this->createItem($product, $sets);

		if($item === null || $item->isNull()){
			$player->sendMessage($this->error("ข้อมูลไอเทมไม่ถูกต้องค่ะ"));
			return false;
		}

		$inventory = $player->getInventory();
		$needed = $item->getCount();
		$owned = 0;

		foreach($inventory->getContents() as $content){
			if($content->equals($item, true, false)){
				$owned += $content->getCount();
			}
		}

		if($owned < $needed){
			$player->sendMessage(
				$this->error(
					"ไอเทมไม่พอค่ะ ต้องมี §e" . $needed .
					" §fชิ้น (มี §e" . $owned . "§f)"
				)
			);
			return false;
		}

		foreach($this->splitToStacks($item) as $stack){
			$inventory->removeItem($stack);
		}

		if(!$this->economy->deposit($player, $totalPrice, "shop-sell:" . $productId)){
			foreach($this->splitToStacks($item) as $stack){
				foreach($inventory->addItem($stack) as $leftover){
					$player->getWorld()->dropItem(
						$player->getPosition()->add(0, 0.5, 0),
						$leftover
					);
				}
			}

			$player->sendMessage($this->error("จ่ายเงินไม่สำเร็จ คืนไอเทมให้แล้วค่ะ"));
			return false;
		}

		$player->sendMessage(
			"§l§c📦 §fขาย §d" . (string) $product["name"] .
			" §fx" . $needed . " §fได้รับ " . $this->money($totalPrice)
		);

		return true;
	}

	/**
	 * แยกไอเทมเป็นกองย่อยตาม max stack
	 *
	 * @return array<int, Item>
	 */
	private function splitToStacks(Item $item) : array{
		$stacks = [];
		$remaining = $item->getCount();
		$maxStack = max(1, $item->getMaxStackSize());

		while($remaining > 0){
			$stack = clone $item;
			$size = min($maxStack, $remaining);

			$stack->setCount($size);
			$stacks[] = $stack;

			$remaining -= $size;
		}

		return $stacks;
	}

	/*
	 * =====================================================
	 * เมนูแอดมิน
	 * =====================================================
	 */

	private function openAdminHome(Player $player) : void{
		$form = new ShopSimpleForm(
			"§l§5⚙ แอดมินร้านค้า",
			"§r§fสินค้าทั้งหมด: §e" . count($this->products) . " §fรายการ\n" .
			"§fหมวดหมู่ทั้งหมด: §e" . count($this->categories) . " §fหมวด\n\n" .
			"§fเลือกเมนูที่ต้องการจัดการค่ะ ✨",
			function(Player $player, int $button) : void{
				match($button){
					0 => $this->openAdminAddHeld($player),
					1 => $this->openAdminCreateCustom($player),
					2 => $this->openAdminEnchantHeld($player),
					3 => $this->openAdminProductList($player),
					4 => $this->openAdminCategoryList($player),
					5 => $this->openShopHome($player),
					default => null
				};
			}
		);

		$form->addButton(
			"§l§a➕ เพิ่มไอเทมในมือ\n§r§fID และไอคอนอัตโนมัติ",
			"textures/items/emerald"
		);

		$form->addButton(
			"§l§d✨ สร้าง Custom Item\n§r§fชื่อ • Lore • Enchant",
			"textures/items/nether_star"
		);

		$form->addButton(
			"§l§b🔮 แต่งไอเทมในมือ\n§r§fเปลี่ยนชื่อ • ใส่เวทมนตร์",
			"textures/items/book_enchanted"
		);

		$form->addButton(
			"§l§e📝 จัดการสินค้า\n§r§fแก้ไข • ลบ • ทดสอบ",
			"textures/items/book_writable"
		);

		$form->addButton(
			"§l§b🗂 จัดการหมวดหมู่\n§r§fเพิ่ม • แก้ไข • ลบ",
			"textures/blocks/chest_front"
		);

		$form->addButton("§l§c↩ กลับหน้าร้านค้า");

		$player->sendForm($form);
	}

	private function openAdminAddHeld(Player $player) : void{
		$held = clone $player->getInventory()->getItemInHand();

		if($held->isNull()){
			$player->sendMessage($this->error("กรุณาถือไอเทมที่ต้องการเพิ่มก่อนค่ะ"));
			return;
		}

		if(count($this->categories) === 0){
			$player->sendMessage($this->error("กรุณาสร้างหมวดหมู่ก่อนค่ะ"));
			$this->openAdminCategoryCreate($player);
			return;
		}

		$identifier = $this->getItemIdentifier($held);
		$autoId = $this->makeUniqueId(
			$this->normalizeId($identifier !== "" ? $identifier : $held->getName())
		);
		$autoIcon = $this->resolveIcon("", $held, $identifier);

		$categoryNames = [];
		$categoryIds = [];

		foreach($this->categories as $category){
			$categoryNames[] = (string) ($category["emoji"] ?? "✨") .
				" " . (string) $category["name"];
			$categoryIds[] = (string) $category["id"];
		}

		$form = new ShopCustomForm(
			"§l§a➕ เพิ่มไอเทมในมือ",
			function(Player $player, array $data) use ($held, $identifier, $categoryIds) : void{
				$categoryId = $categoryIds[(int) ($data[1] ?? 0)] ?? "";
				$productId = $this->normalizeId((string) ($data[2] ?? ""));
				$displayName = trim((string) ($data[3] ?? ""));
				$amount = $this->parseInteger((string) ($data[4] ?? ""));
				$buyPrice = $this->parsePrice((string) ($data[5] ?? ""), true);
				$sellPrice = $this->parsePrice((string) ($data[6] ?? ""), true);
				$icon = trim((string) ($data[7] ?? ""));

				if($categoryId === ""){
					$player->sendMessage($this->error("ไม่พบหมวดหมู่ค่ะ"));
					return;
				}

				if($productId === ""){
					$productId = $this->makeUniqueId(
						$this->normalizeId($identifier !== "" ? $identifier : "shop")
					);
				}

				if($this->getProduct($productId) !== null){
					$player->sendMessage($this->error("Shop ID นี้ถูกใช้แล้วค่ะ"));
					return;
				}

				if($displayName === ""){
					$displayName = $held->hasCustomName()
						? $held->getCustomName()
						: $held->getName();
				}

				if($amount === null || $buyPrice === null || $sellPrice === null){
					$player->sendMessage($this->error("จำนวนหรือราคาไม่ถูกต้องค่ะ"));
					return;
				}

				if($buyPrice <= 0 && $sellPrice <= 0){
					$player->sendMessage(
						$this->error("ต้องกำหนดราคาซื้อหรือขายอย่างน้อยหนึ่งอย่างค่ะ")
					);
					return;
				}

				if($icon === ""){
					$icon = $this->resolveIcon("", $held, $identifier);
				}

				try{
					$template = clone $held;
					$template->setCount(1);

					$this->products[] = [
						"id" => $productId,
						"category" => $categoryId,
						"name" => $displayName,
						"base" => $identifier,
						"amount" => $amount,
						"buy" => $buyPrice,
						"sell" => $sellPrice,
						"icon" => $icon,
						"custom-name" => "",
						"custom-id" => "",
						"lore" => [],
						"item-nbt" => $this->encodeItem($template)
					];

					$this->save();

					$player->sendMessage(
						$this->success(
							"เพิ่มสินค้า §d" . $displayName . " §fแล้ว\n" .
							"§r§fShop ID: §e" . $productId . "\n" .
							"§fซื้อ: §a" . number_format($buyPrice, 2) .
							" §f• ขาย: §c" . number_format($sellPrice, 2)
						)
					);
				}catch(\Throwable $e){
					$this->plugin->getLogger()->error("เพิ่มสินค้าไม่สำเร็จ: " . $e->getMessage());
					$player->sendMessage($this->error("บันทึกไอเทมไม่สำเร็จค่ะ"));
				}
			}
		);

		$form->addLabel(
			"§fไอเทม: §e" . $held->getName() . "\n" .
			"§fItem ID: §b" . ($identifier !== "" ? $identifier : "ไม่ทราบ") . "\n" .
			"§fจำนวนในมือ: §e" . $held->getCount() . "\n\n" .
			"§fตั้งราคาเป็น §e0 §fหมายถึงปิดโหมดนั้นค่ะ"
		);

		$form->addDropdown("§bหมวดหมู่สินค้า", $categoryNames);
		$form->addInput("§eShop ID (อัตโนมัติ)", "auto", $autoId);
		$form->addInput(
			"§dชื่อแสดงในร้าน",
			"",
			$held->hasCustomName() ? $held->getCustomName() : $held->getName()
		);
		$form->addInput("§fจำนวนต่อชุด", "1", (string) max(1, $held->getCount()));
		$form->addInput("§aราคาซื้อ (0 = ไม่ขาย)", "100", "100");
		$form->addInput("§cราคาขาย (0 = ไม่รับซื้อ)", "50", "50");
		$form->addInput("§bIcon (อัตโนมัติ)", "เว้นว่าง = ค้นหาอัตโนมัติ", $autoIcon);

		$player->sendForm($form);
	}

	private function openAdminCreateCustom(Player $player) : void{
		if(count($this->categories) === 0){
			$player->sendMessage($this->error("กรุณาสร้างหมวดหมู่ก่อนค่ะ"));
			$this->openAdminCategoryCreate($player);
			return;
		}

		$categoryNames = [];
		$categoryIds = [];

		foreach($this->categories as $category){
			$categoryNames[] = (string) ($category["emoji"] ?? "✨") .
				" " . (string) $category["name"];
			$categoryIds[] = (string) $category["id"];
		}

		$enchantOptions = $this->getEnchantmentOptions();

		$form = new ShopCustomForm(
			"§l§d✨ สร้าง Custom Item",
			function(Player $player, array $data) use ($categoryIds) : void{
				$identifier = trim((string) ($data[1] ?? ""));
				$categoryId = $categoryIds[(int) ($data[2] ?? 0)] ?? "";
				$productId = $this->normalizeId((string) ($data[3] ?? ""));
				$name = trim((string) ($data[4] ?? ""));
				$loreText = trim((string) ($data[5] ?? ""));
				$amount = $this->parseInteger((string) ($data[12] ?? ""));
				$buyPrice = $this->parsePrice((string) ($data[13] ?? ""), true);
				$sellPrice = $this->parsePrice((string) ($data[14] ?? ""), true);
				$icon = trim((string) ($data[15] ?? ""));
				$unbreakable = (bool) ($data[16] ?? false);

				if($identifier === "" || $name === "" || $categoryId === ""){
					$player->sendMessage($this->error("กรอกข้อมูลให้ครบค่ะ"));
					return;
				}

				if($amount === null || $buyPrice === null || $sellPrice === null){
					$player->sendMessage($this->error("จำนวนหรือราคาไม่ถูกต้องค่ะ"));
					return;
				}

				$item = $this->parseItemIdentifier($identifier);

				if($item === null){
					$player->sendMessage($this->error("ไม่พบ Item ID: §e" . $identifier));
					return;
				}

				if($productId === ""){
					$productId = $this->makeUniqueId(
						"custom_" . $this->normalizeId($identifier)
					);
				}

				if($this->getProduct($productId) !== null){
					$player->sendMessage($this->error("Custom ID นี้ถูกใช้แล้วค่ะ"));
					return;
				}

				$item->setCount(1);
				$item->setCustomName($name);

				$lore = [];

				if($loreText !== ""){
					$lore = array_values(array_filter(
						array_map(
							static fn(string $line) : string => trim($line),
							explode("|", $loreText)
						),
						static fn(string $line) : bool => $line !== ""
					));

					$item->setLore($lore);
				}

				$enchantCount = 0;

				foreach([[6, 7], [8, 9], [10, 11]] as $pair){
					if($this->applyEnchantment(
						$item,
						(int) ($data[$pair[0]] ?? 0),
						(string) ($data[$pair[1]] ?? "1")
					)){
						$enchantCount++;
					}
				}

				if($unbreakable){
					$item->setUnbreakable(true);
				}

				$tag = $item->getNamedTag();
				$tag->setTag("SimpleMoneyCustomId", new StringTag($productId));
				$item->setNamedTag($tag);

				if($icon === ""){
					$icon = $this->resolveIcon("", $item, $identifier);
				}

				try{
					$this->products[] = [
						"id" => $productId,
						"category" => $categoryId,
						"name" => $name,
						"base" => $identifier,
						"amount" => $amount,
						"buy" => $buyPrice,
						"sell" => $sellPrice,
						"icon" => $icon,
						"custom-name" => $name,
						"custom-id" => $productId,
						"lore" => $lore,
						"item-nbt" => $this->encodeItem($item)
					];

					$this->save();

					$preview = clone $item;
					$preview->setCount($amount);

					foreach($player->getInventory()->addItem($preview) as $leftover){
						$player->getWorld()->dropItem(
							$player->getPosition()->add(0, 0.5, 0),
							$leftover
						);
					}

					$player->sendMessage(
						$this->success(
							"สร้าง Custom Item สำเร็จ ✨\n" .
							"§r§fCustom ID: §e" . $productId . "\n" .
							"§fเวทมนตร์: §d" . $enchantCount . " §fรายการ"
						)
					);
				}catch(\Throwable $e){
					$this->plugin->getLogger()->error(
						"สร้าง Custom Item ไม่สำเร็จ: " . $e->getMessage()
					);
					$player->sendMessage($this->error("บันทึกไม่สำเร็จค่ะ"));
				}
			}
		);

		$form->addLabel(
			"§dCustom Item §fใช้ไอเทม Vanilla เป็นฐาน\n" .
			"§fตั้งชื่อ • Lore • เวทมนตร์ • เลเวลสูงพิเศษ\n" .
			"§fแนะนำเลเวลไม่เกิน §e255 §fเพื่อความเสถียร"
		);

		$form->addInput("§bBase Item ID", "minecraft:diamond_sword");
		$form->addDropdown("§bหมวดหมู่สินค้า", $categoryNames);
		$form->addInput("§eCustom ID (เว้นว่าง = อัตโนมัติ)", "auto");
		$form->addInput("§dชื่อไอเทม", "§d✦ ดาบแห่งดวงดาว ✦");
		$form->addInput("§5Lore คั่นด้วย |", "§fอาวุธในตำนาน|§dLegendary");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 1", $enchantOptions);
		$form->addInput("§fเลเวลเวทมนตร์ที่ 1", "1", "1");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 2", $enchantOptions);
		$form->addInput("§fเลเวลเวทมนตร์ที่ 2", "1", "1");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 3", $enchantOptions);
		$form->addInput("§fเลเวลเวทมนตร์ที่ 3", "1", "1");

		$form->addInput("§fจำนวนต่อชุด", "1", "1");
		$form->addInput("§aราคาซื้อ", "5000", "5000");
		$form->addInput("§cราคาขาย", "2500", "2500");
		$form->addInput("§bIcon (เว้นว่าง = อัตโนมัติ)", "เช่น textures/items/diamond_sword");
		$form->addToggle("§e🛡 ไอเทมไม่มีวันพัง (Unbreakable)", false);

		$player->sendForm($form);
	}

	private function openAdminEnchantHeld(Player $player) : void{
		$held = $player->getInventory()->getItemInHand();

		if($held->isNull()){
			$player->sendMessage($this->error("กรุณาถือไอเทมที่ต้องการแต่งก่อนค่ะ"));
			return;
		}

		$enchantOptions = $this->getEnchantmentOptions();

		$form = new ShopCustomForm(
			"§l§b🔮 แต่งไอเทมในมือ",
			function(Player $player, array $data) : void{
				$item = clone $player->getInventory()->getItemInHand();

				if($item->isNull()){
					$player->sendMessage($this->error("ไอเทมในมือหายไปค่ะ"));
					return;
				}

				$newName = trim((string) ($data[1] ?? ""));
				$loreText = trim((string) ($data[2] ?? ""));
				$clearOld = (bool) ($data[9] ?? false);
				$unbreakable = (bool) ($data[10] ?? false);

				if($clearOld){
					$item->removeEnchantments();
				}

				if($newName !== ""){
					$item->setCustomName($newName);
				}

				if($loreText !== ""){
					$item->setLore(array_values(array_filter(
						array_map(
							static fn(string $line) : string => trim($line),
							explode("|", $loreText)
						),
						static fn(string $line) : bool => $line !== ""
					)));
				}

				$applied = 0;

				foreach([[3, 4], [5, 6], [7, 8]] as $pair){
					if($this->applyEnchantment(
						$item,
						(int) ($data[$pair[0]] ?? 0),
						(string) ($data[$pair[1]] ?? "1")
					)){
						$applied++;
					}
				}

				if($unbreakable){
					$item->setUnbreakable(true);
				}

				$player->getInventory()->setItemInHand($item);

				$player->sendMessage(
					$this->success(
						"แต่งไอเทมสำเร็จแล้วค่ะ ✨\n" .
						"§r§fใส่เวทมนตร์ใหม่: §d" . $applied . " §fรายการ"
					)
				);
			}
		);

		$form->addLabel(
			"§fไอเทมในมือ: §e" . $held->getName() . "\n" .
			"§fเว้นว่างช่องไหน = ไม่เปลี่ยนค่าเดิม"
		);

		$form->addInput(
			"§dเปลี่ยนชื่อไอเทม",
			"§d✦ ชื่อใหม่ ✦",
			$held->hasCustomName() ? $held->getCustomName() : ""
		);

		$form->addInput("§5Lore คั่นด้วย |", "§fบรรทัด 1|§fบรรทัด 2");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 1", $enchantOptions);
		$form->addInput("§fเลเวลที่ 1", "1", "1");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 2", $enchantOptions);
		$form->addInput("§fเลเวลที่ 2", "1", "1");

		$form->addDropdown("§b🔮 เวทมนตร์ที่ 3", $enchantOptions);
		$form->addInput("§fเลเวลที่ 3", "1", "1");

		$form->addToggle("§c♻ ล้างเวทมนตร์เดิมทั้งหมด", false);
		$form->addToggle("§e🛡 ไอเทมไม่มีวันพัง", false);

		$player->sendForm($form);
	}

	private function openAdminProductList(Player $player) : void{
		$products = $this->getProducts();

		$form = new ShopSimpleForm(
			"§l§e📝 จัดการสินค้า",
			"§r§fเลือกสินค้าที่ต้องการจัดการค่ะ\n" .
			"§fทั้งหมด §e" . count($products) . " §fรายการ",
			function(Player $player, int $button) use ($products) : void{
				if(isset($products[$button])){
					$this->openAdminProduct($player, (string) $products[$button]["id"]);
					return;
				}

				$this->openAdminHome($player);
			}
		);

		foreach($products as $product){
			$category = $this->getCategory((string) ($product["category"] ?? ""));

			$form->addButton(
				"§l§f" . (string) ($product["name"] ?? "Unknown") . "\n" .
				"§r§f" . ($category !== null ? (string) $category["name"] : "-") .
				" §8• §e" . (string) ($product["id"] ?? ""),
				$this->resolveIcon(trim((string) ($product["icon"] ?? "")), $this->createItem($product, 1))
			);
		}

		$form->addButton("§l§c↩ กลับ");

		$player->sendForm($form);
	}

	private function openAdminProduct(Player $player, string $productId) : void{
		$product = $this->getProduct($productId);

		if($product === null){
			$player->sendMessage($this->error("ไม่พบสินค้านี้ค่ะ"));
			return;
		}

		$category = $this->getCategory((string) ($product["category"] ?? ""));

		$form = new ShopSimpleForm(
			"§l§e⚙ " . (string) ($product["name"] ?? $productId),
			"§r§fShop ID: §e" . $productId . "\n" .
			"§fหมวดหมู่: §b" . ($category !== null ? (string) $category["name"] : "-") . "\n" .
			"§fItem ID: §f" . (string) ($product["base"] ?? "-") . "\n" .
			"§fจำนวนต่อชุด: §e" . (int) ($product["amount"] ?? 1) . "\n" .
			"§aราคาซื้อ: §e" . number_format((float) ($product["buy"] ?? 0), 2) . "\n" .
			"§cราคาขาย: §e" . number_format((float) ($product["sell"] ?? 0), 2),
			function(Player $player, int $button) use ($productId) : void{
				match($button){
					0 => $this->openAdminEditProduct($player, $productId),
					1 => $this->giveProduct($player, $productId),
					2 => $this->openAdminDeleteProduct($player, $productId),
					3 => $this->openAdminProductList($player),
					default => null
				};
			}
		);

		$form->addButton("§l§e✏ แก้ไขสินค้า");
		$form->addButton("§l§b🎁 รับไอเทมทดสอบ");
		$form->addButton("§l§c🗑 ลบสินค้า");
		$form->addButton("§l§f↩ กลับ");

		$player->sendForm($form);
	}

	private function openAdminEditProduct(Player $player, string $productId) : void{
		$product = $this->getProduct($productId);

		if($product === null){
			return;
		}

		$categoryNames = [];
		$categoryIds = [];
		$defaultIndex = 0;

		foreach($this->categories as $index => $category){
			$categoryNames[] = (string) ($category["emoji"] ?? "✨") .
				" " . (string) $category["name"];
			$categoryIds[] = (string) $category["id"];

			if((string) $category["id"] === (string) ($product["category"] ?? "")){
				$defaultIndex = $index;
			}
		}

		$form = new ShopCustomForm(
			"§l§e✏ แก้ไขสินค้า",
			function(Player $player, array $data) use ($productId, $categoryIds) : void{
				$name = trim((string) ($data[1] ?? ""));
				$categoryId = $categoryIds[(int) ($data[2] ?? 0)] ?? "";
				$amount = $this->parseInteger((string) ($data[3] ?? ""));
				$buy = $this->parsePrice((string) ($data[4] ?? ""), true);
				$sell = $this->parsePrice((string) ($data[5] ?? ""), true);
				$icon = trim((string) ($data[6] ?? ""));

				if(
					$name === "" || $categoryId === "" ||
					$amount === null || $buy === null || $sell === null
				){
					$player->sendMessage($this->error("ข้อมูลไม่ถูกต้องค่ะ"));
					return;
				}

				foreach($this->products as $index => $current){
					if((string) ($current["id"] ?? "") !== $productId){
						continue;
					}

					$this->products[$index]["name"] = $name;
					$this->products[$index]["category"] = $categoryId;
					$this->products[$index]["amount"] = $amount;
					$this->products[$index]["buy"] = $buy;
					$this->products[$index]["sell"] = $sell;
					$this->products[$index]["icon"] = $this->resolveIcon($icon, $this->createItem($this->products[$index], 1));

					$this->save();

					$player->sendMessage($this->success("แก้ไขสินค้าเรียบร้อยแล้วค่ะ"));
					$this->openAdminProduct($player, $productId);
					return;
				}
			}
		);

		$form->addLabel("§fShop ID: §e" . $productId);
		$form->addInput("§dชื่อสินค้า", "", (string) ($product["name"] ?? ""));
		$form->addDropdown("§bหมวดหมู่", $categoryNames, $defaultIndex);
		$form->addInput("§fจำนวนต่อชุด", "", (string) ($product["amount"] ?? 1));
		$form->addInput("§aราคาซื้อ", "", (string) ($product["buy"] ?? 0));
		$form->addInput("§cราคาขาย", "", (string) ($product["sell"] ?? 0));
		$form->addInput("§bIcon", "", (string) ($product["icon"] ?? ""));

		$player->sendForm($form);
	}

	private function openAdminDeleteProduct(Player $player, string $productId) : void{
		$product = $this->getProduct($productId);

		if($product === null){
			return;
		}

		$form = new ShopModalForm(
			"§l§c🗑 ยืนยันการลบ",
			"§r§fลบสินค้า\n\n§d" . (string) ($product["name"] ?? $productId) .
			"\n\n§cการลบไม่สามารถย้อนกลับได้",
			"§l§c✔ ลบเลย",
			"§l§a✘ ยกเลิก",
			function(Player $player, bool $confirm) use ($productId) : void{
				if(!$confirm){
					$this->openAdminProduct($player, $productId);
					return;
				}

				if($this->removeProduct($productId)){
					$player->sendMessage($this->success("ลบสินค้าเรียบร้อยแล้วค่ะ"));
				}

				$this->openAdminProductList($player);
			}
		);

		$player->sendForm($form);
	}

	public function removeProduct(string $productId) : bool{
		foreach($this->products as $index => $product){
			if((string) ($product["id"] ?? "") !== $productId){
				continue;
			}

			unset($this->products[$index]);
			$this->products = array_values($this->products);
			$this->save();

			return true;
		}

		return false;
	}

	private function giveProduct(Player $player, string $productId) : void{
		$product = $this->getProduct($productId);

		if($product === null){
			return;
		}

		$item = $this->createItem($product, 1);

		if($item === null){
			$player->sendMessage($this->error("สร้างไอเทมไม่สำเร็จค่ะ"));
			return;
		}

		foreach($this->splitToStacks($item) as $stack){
			foreach($player->getInventory()->addItem($stack) as $leftover){
				$player->getWorld()->dropItem(
					$player->getPosition()->add(0, 0.5, 0),
					$leftover
				);
			}
		}

		$player->sendMessage($this->success("ได้รับไอเทมทดสอบแล้วค่ะ 🎁"));
	}

	/*
	 * =====================================================
	 * จัดการหมวดหมู่
	 * =====================================================
	 */

	private function openAdminCategoryList(Player $player) : void{
		$categories = $this->getCategories();

		$form = new ShopSimpleForm(
			"§l§b🗂 จัดการหมวดหมู่",
			"§r§fหมวดหมู่ทั้งหมด §e" . count($categories) . " §fหมวด\n" .
			"§fกดเพื่อแก้ไข หรือกดปุ่มล่างเพื่อสร้างใหม่ค่ะ",
			function(Player $player, int $button) use ($categories) : void{
				if(isset($categories[$button])){
					$this->openAdminCategoryEdit(
						$player,
						(string) $categories[$button]["id"]
					);
					return;
				}

				if($button === count($categories)){
					$this->openAdminCategoryCreate($player);
					return;
				}

				$this->openAdminHome($player);
			}
		);

		foreach($categories as $category){
			$count = count($this->getProductsByCategory((string) $category["id"]));

			$form->addButton(
				"§l§f" . (string) ($category["emoji"] ?? "✨") .
				" " . (string) $category["name"] . "\n" .
				"§r§fสินค้า §e" . $count . " §fรายการ",
				$this->resolveIcon(trim((string) ($category["icon"] ?? "")))
			);
		}

		$form->addButton("§l§a➕ สร้างหมวดหมู่ใหม่\n§r§fตั้งชื่อและไอคอนเองได้");
		$form->addButton("§l§c↩ กลับ");

		$player->sendForm($form);
	}

	private function openAdminCategoryCreate(Player $player) : void{
		$form = new ShopCustomForm(
			"§l§a➕ สร้างหมวดหมู่",
			function(Player $player, array $data) : void{
				$name = trim((string) ($data[1] ?? ""));
				$emoji = trim((string) ($data[2] ?? ""));
				$icon = trim((string) ($data[3] ?? ""));

				if($name === ""){
					$player->sendMessage($this->error("กรุณาตั้งชื่อหมวดหมู่ค่ะ"));
					return;
				}

				foreach($this->categories as $category){
					if((string) $category["name"] === $name){
						$player->sendMessage($this->error("มีหมวดหมู่ชื่อนี้แล้วค่ะ"));
						return;
					}
				}

				$this->categories[] = [
					"id" => $this->makeUniqueCategoryId($name),
					"name" => $name,
					"emoji" => $emoji !== "" ? $emoji : "✨",
					"icon" => $icon
				];

				$this->save();

				$player->sendMessage(
					$this->success("สร้างหมวดหมู่ §d" . $name . " §fแล้วค่ะ ✨")
				);

				$this->openAdminCategoryList($player);
			}
		);

		$form->addLabel(
			"§fสร้างหมวดหมู่ใหม่สำหรับร้านค้า\n" .
			"§fตัวอย่างไอคอน: textures/items/diamond"
		);

		$form->addInput("§dชื่อหมวดหมู่", "เช่น ของหายาก");
		$form->addInput("§eEmoji", "✨", "✨");
		$form->addInput("§bIcon path หรือ URL", "textures/items/diamond");

		$player->sendForm($form);
	}

	private function openAdminCategoryEdit(Player $player, string $categoryId) : void{
		$category = $this->getCategory($categoryId);

		if($category === null){
			return;
		}

		$form = new ShopCustomForm(
			"§l§e✏ แก้ไขหมวดหมู่",
			function(Player $player, array $data) use ($categoryId) : void{
				$name = trim((string) ($data[1] ?? ""));
				$emoji = trim((string) ($data[2] ?? ""));
				$icon = trim((string) ($data[3] ?? ""));
				$delete = (bool) ($data[4] ?? false);

				if($delete){
					$this->openAdminCategoryDelete($player, $categoryId);
					return;
				}

				if($name === ""){
					$player->sendMessage($this->error("ชื่อหมวดหมู่ห้ามว่างค่ะ"));
					return;
				}

				foreach($this->categories as $index => $current){
					if((string) $current["id"] !== $categoryId){
						continue;
					}

					$this->categories[$index]["name"] = $name;
					$this->categories[$index]["emoji"] = $emoji !== "" ? $emoji : "✨";
					$this->categories[$index]["icon"] = $this->resolveIcon($icon);

					$this->save();

					$player->sendMessage($this->success("แก้ไขหมวดหมู่เรียบร้อยแล้วค่ะ"));
					$this->openAdminCategoryList($player);
					return;
				}
			}
		);

		$count = count($this->getProductsByCategory($categoryId));

		$form->addLabel(
			"§fหมวดหมู่: §d" . (string) $category["name"] . "\n" .
			"§fสินค้าในหมวด: §e" . $count . " §fรายการ"
		);

		$form->addInput("§dชื่อหมวดหมู่", "", (string) $category["name"]);
		$form->addInput("§eEmoji", "", (string) ($category["emoji"] ?? "✨"));
		$form->addInput("§bIcon", "", (string) ($category["icon"] ?? ""));
		$form->addToggle("§c🗑 ลบหมวดหมู่นี้", false);

		$player->sendForm($form);
	}

	private function openAdminCategoryDelete(Player $player, string $categoryId) : void{
		$category = $this->getCategory($categoryId);

		if($category === null){
			return;
		}

		$count = count($this->getProductsByCategory($categoryId));

		$form = new ShopModalForm(
			"§l§c🗑 ลบหมวดหมู่",
			"§r§fลบหมวดหมู่ §d" . (string) $category["name"] . "\n\n" .
			"§fสินค้าในหมวดนี้: §e" . $count . " §fรายการ\n" .
			"§fสินค้าจะถูกย้ายไปหมวด §fไอเทมอื่น ๆ §fอัตโนมัติ",
			"§l§c✔ ลบหมวดหมู่",
			"§l§a✘ ยกเลิก",
			function(Player $player, bool $confirm) use ($categoryId) : void{
				if(!$confirm){
					$this->openAdminCategoryList($player);
					return;
				}

				$fallbackId = $this->ensureCategory("ไอเทมอื่น ๆ", "✨", "");

				if($fallbackId === $categoryId){
					$player->sendMessage($this->error("ไม่สามารถลบหมวดหมู่พื้นฐานได้ค่ะ"));
					return;
				}

				foreach($this->products as $index => $product){
					if((string) ($product["category"] ?? "") === $categoryId){
						$this->products[$index]["category"] = $fallbackId;
					}
				}

				foreach($this->categories as $index => $current){
					if((string) $current["id"] === $categoryId){
						unset($this->categories[$index]);
					}
				}

				$this->categories = array_values($this->categories);
				$this->save();

				$player->sendMessage($this->success("ลบหมวดหมู่เรียบร้อยแล้วค่ะ"));
				$this->openAdminCategoryList($player);
			}
		);

		$player->sendForm($form);
	}

	/*
	 * =====================================================
	 * ระบบ Enchantment
	 * =====================================================
	 */

	/** @return array<int, string> */
	private function getEnchantmentAliases() : array{
		try{
			$aliases = StringToEnchantmentParser::getInstance()->getKnownAliases();
		}catch(\Throwable){
			return [];
		}

		$aliases = array_values(array_unique($aliases));
		sort($aliases);

		return $aliases;
	}

	/** @return array<int, string> */
	private function getEnchantmentOptions() : array{
		$options = ["§f— ไม่ใส่เวทมนตร์ —"];

		foreach($this->getEnchantmentAliases() as $alias){
			$options[] = "§d" . $alias;
		}

		return $options;
	}

	private function applyEnchantment(
		Item $item,
		int $dropdownIndex,
		string $levelInput
	) : bool{
		if($dropdownIndex <= 0){
			return false;
		}

		$aliases = $this->getEnchantmentAliases();
		$alias = $aliases[$dropdownIndex - 1] ?? "";

		if($alias === ""){
			return false;
		}

		try{
			$enchantment = StringToEnchantmentParser::getInstance()->parse($alias);
		}catch(\Throwable){
			return false;
		}

		if($enchantment === null){
			return false;
		}

		$level = is_numeric($levelInput) ? (int) $levelInput : 1;
		$level = max(1, min(32767, $level));

		try{
			$item->addEnchantment(new EnchantmentInstance($enchantment, $level));
		}catch(\Throwable $e){
			$this->plugin->getLogger()->warning(
				"ใส่เวทมนตร์ {$alias} ไม่สำเร็จ: " . $e->getMessage()
			);
			return false;
		}

		return true;
	}

	/*
	 * =====================================================
	 * Item Handling
	 * =====================================================
	 */

	/**
	 * @param array<string, mixed> $product
	 */
	public function createItem(array $product, int $sets = 1) : ?Item{
		$sets = max(1, $sets);
		$perSet = max(1, (int) ($product["amount"] ?? $product["quantity"] ?? 1));

		$encoded = trim((string) ($product["item-nbt"] ?? ""));

		if($encoded !== ""){
			$item = $this->decodeItem($encoded);

			if($item !== null){
				$item->setCount($perSet * $sets);
				return $item;
			}
		}

		$identifier = trim((string) ($product["base"] ?? $product["item"] ?? ""));

		if($identifier === ""){
			return null;
		}

		$item = $this->parseItemIdentifier($identifier);

		if($item === null){
			return null;
		}

		$item->setCount($perSet * $sets);

		$customName = trim((string) ($product["custom-name"] ?? ""));

		if($customName !== ""){
			$item->setCustomName($customName);
		}

		$lore = $product["lore"] ?? [];

		if(is_array($lore) && count($lore) > 0){
			$item->setLore(array_map("strval", $lore));
		}

		$customId = trim((string) ($product["custom-id"] ?? ""));

		if($customId !== ""){
			$tag = $item->getNamedTag();
			$tag->setTag("SimpleMoneyCustomId", new StringTag($customId));
			$item->setNamedTag($tag);
		}

		return $item;
	}

	private function encodeItem(Item $item) : string{
		$copy = clone $item;
		$copy->setCount(1);

		$binary = (new LittleEndianNbtSerializer())->write(
			new TreeRoot($copy->nbtSerialize())
		);

		return base64_encode($binary);
	}

	private function decodeItem(string $encoded) : ?Item{
		try{
			$binary = base64_decode($encoded, true);

			if($binary === false){
				return null;
			}

			$root = (new LittleEndianNbtSerializer())->read($binary);

			return Item::nbtDeserialize($root->mustGetCompoundTag());
		}catch(\Throwable $e){
			$this->plugin->getLogger()->warning("อ่าน Item NBT ไม่สำเร็จ: " . $e->getMessage());
			return null;
		}
	}

	private function parseItemIdentifier(string $identifier) : ?Item{
		$identifier = trim($identifier);

		if($identifier === ""){
			return null;
		}

		try{
			$item = StringToItemParser::getInstance()->parse($identifier);

			if($item !== null){
				return $item;
			}

			if(str_starts_with($identifier, "minecraft:")){
				return StringToItemParser::getInstance()->parse(
					substr($identifier, strlen("minecraft:"))
				);
			}
		}catch(\Throwable){
			return null;
		}

		return null;
	}

	private function getItemIdentifier(Item $item) : string{
		try{
			return GlobalItemDataHandlers::getSerializer()
				->serializeType($item)
				->getName();
		}catch(\Throwable){
			try{
				$aliases = StringToItemParser::getInstance()->lookupAliases($item);

				if(count($aliases) > 0){
					return "minecraft:" . $aliases[0];
				}
			}catch(\Throwable){
				return "";
			}
		}

		return "";
	}

	/**
	 * Resolve a Bedrock form icon path automatically.
	 *
	 * Supports:
	 * - Full paths: textures/items/..., textures/blocks/...
	 * - Short paths: items/..., blocks/...
	 * - Vanilla item IDs: minecraft:diamond, melon_slice, etc.
	 * - Custom namespace IDs (best-effort): customies:namespace:item
	 * - Known Bedrock vanilla texture aliases where the ID differs from the
	 *   actual texture filename (for example melon_slice -> melon).
	 */
	/**
	 * Reads texture IDs/paths from the resource packs used by the server.
	 *
	 * This intentionally does NOT copy vanilla PNGs. It only reads the
	 * item_texture.json and terrain_texture.json metadata that already exist
	 * in the server's resource_packs directory.
	 */
	private function loadResourcePackTextureIndex() : void{
		$this->resourcePackTextureIndex = [
			"items" => [],
			"blocks" => []
		];

		$resourcePackManager = $this->plugin->getServer()->getResourcePackManager();
		$root = rtrim($resourcePackManager->getPath(), DIRECTORY_SEPARATOR);

		$packNames = [];

		// Follow the configured stack first (top priority first).
		$configPath = $root . DIRECTORY_SEPARATOR . "resource_packs.yml";
		if(is_file($configPath)){
			try{
				$config = new \pocketmine\utils\Config($configPath, \pocketmine\utils\Config::YAML, []);
				$stack = $config->get("resource_stack", []);
				if(is_array($stack)){
					foreach($stack as $entry){
						$name = trim((string) $entry);
						if($name !== ""){
							$packNames[] = $name;
						}
					}
				}
			}catch(\Throwable){
				// Fall back to scanning all packs below.
			}
		}

		// Include other packs too, without duplicating names.
		try{
			foreach(scandir($root) ?: [] as $entry){
				if($entry === "." || $entry === ".." || str_ends_with(strtolower($entry), ".key")){
					continue;
				}
				if(preg_match('/\.(zip|mcpack)$/i', $entry) === 1){
					$packNames[] = $entry;
				}
			}
		}catch(\Throwable){
			// Keep whatever the configured stack provided.
		}

		$seen = [];
		foreach($packNames as $packName){
			$lowerPack = strtolower($packName);
			if(isset($seen[$lowerPack])){
				continue;
			}
			$seen[$lowerPack] = true;

			$packPath = $root . DIRECTORY_SEPARATOR . $packName;
			if(!is_file($packPath) || !class_exists(\ZipArchive::class)){
				continue;
			}

			try{
				$zip = new \ZipArchive();
				if($zip->open($packPath) !== true){
					continue;
				}

				// Most Bedrock packs place textures at the archive root.
				// Also support packs wrapped in one directory.
				foreach([
					["items", "textures/item_texture.json"],
					["blocks", "textures/terrain_texture.json"]
				] as [$kind, $jsonPath]){
					$contents = $zip->getFromName($jsonPath);

					if($contents === false){
						for($i = 0, $count = $zip->numFiles; $i < $count; ++$i){
							$stat = $zip->statIndex($i);
							$name = is_array($stat) ? (string) ($stat["name"] ?? "") : "";
							if(preg_match(
								'~(?:^|/)' . preg_quote($jsonPath, '~') . '$~i',
								$name
							) === 1){
								$contents = $zip->getFromIndex($i);
								break;
							}
						}
					}

					if(!is_string($contents) || $contents === ""){
						continue;
					}

					$data = json_decode($contents, true);
					$table = $data["texture_data"] ?? null;

					if(!is_array($table)){
						continue;
					}

					foreach($table as $key => $entry){
						$paths = $this->extractTexturePaths($entry["textures"] ?? null);

						if(count($paths) === 0){
							continue;
						}

						$key = strtolower(trim((string) $key));
						if($key === ""){
							continue;
						}

						// Highest-priority pack stays first.
						if(!isset($this->resourcePackTextureIndex[$kind][$key])){
							$this->resourcePackTextureIndex[$kind][$key] = $paths;
						}
					}
				}

				$zip->close();
			}catch(\Throwable){
				// One bad pack must not break the shop.
			}
		}
	}

	/** @return array<int, string> */
	private function extractTexturePaths(mixed $value) : array{
		$result = [];

		if(is_string($value)){
			$value = [$value];
		}

		if(!is_array($value)){
			return [];
		}

		foreach($value as $entry){
			if(is_string($entry)){
				$result[] = ltrim(str_replace("\\", "/", trim($entry)), "/");
				continue;
			}

			if(is_array($entry)){
				$path = $entry["path"] ?? $entry["texture"] ?? null;
				if(is_string($path) && trim($path) !== ""){
					$result[] = ltrim(str_replace("\\", "/", trim($path)), "/");
				}
			}
		}

		return array_values(array_unique(array_filter(
			$result,
			static fn(string $path) : bool => str_starts_with($path, "textures/")
		)));
	}

	private function getResourcePackTexture(string $identifier) : string{
		$name = strtolower(trim($identifier));
		$name = preg_replace('/^minecraft:/', '', $name) ?? $name;

		if($name === ""){
			return "";
		}

		// Try exact aliases first.
		foreach(["blocks", "items"] as $kind){
			$table = $this->resourcePackTextureIndex[$kind] ?? [];
			if(isset($table[$name]) && isset($table[$name][0])){
				return (string) $table[$name][0];
			}
		}

		// Prefer block-like entries for names known to have a block texture.
		$blocks = $this->resourcePackTextureIndex["blocks"] ?? [];
		$preferredSuffixes = [
			"_front", "_side", "_normal", "_top", "_bottom",
			"_side0", "_texture", ""
		];

		foreach($preferredSuffixes as $suffix){
			$key = $name . $suffix;
			if(isset($blocks[$key][0])){
				return (string) $blocks[$key][0];
			}
		}

		// Prefix search covers multi-face names such as mangrove_roots_side.
		foreach($blocks as $key => $paths){
			if(str_starts_with($key, $name . "_") && isset($paths[0])){
				return (string) $paths[0];
			}
		}

		$items = $this->resourcePackTextureIndex["items"] ?? [];
		foreach($items as $key => $paths){
			if($key === $name || str_starts_with($key, $name . "_")){
				if(isset($paths[0])){
					return (string) $paths[0];
				}
			}
		}

		return "";
	}

	private function resolveIcon(string $icon = "", ?Item $item = null, string $identifier = "") : string{
		$icon = trim(str_replace("\\", "/", $icon));

		// URLs are valid form button images and must be kept untouched.
		if(str_starts_with($icon, "https://") || str_starts_with($icon, "http://")){
			return $icon;
		}

		// Remove accidental leading slash.
		$icon = ltrim($icon, "/");

		if($identifier === "" && $item !== null){
			$identifier = $this->getItemIdentifier($item);
		}

		if($icon === ""){
			$resourcePackIcon = $this->getResourcePackTexture($identifier);
			if($resourcePackIcon !== ""){
				return $resourcePackIcon;
			}
		}

		if($icon !== ""){
			$icon = $this->normalizeTexturePath($icon, $item);
			if($icon !== ""){
				return $icon;
			}
		}

		$name = strtolower(trim($identifier));
		$name = preg_replace('/^minecraft:/', '', $name) ?? $name;

		if($name === ""){
			return "";
		}

		// Custom item namespaces: there is no universal texture filename API
		// exposed by PocketMine. Keep a predictable path as a fallback; if
		// Customies (or another plugin) stores an explicit icon in shops.json,
		// that explicit path wins above.
		if(str_contains($identifier, ":") && !str_starts_with(strtolower($identifier), "minecraft:")){
			$parts = explode(":", $identifier);
			$name = strtolower(trim((string) end($parts)));
		}

		$aliases = $this->vanillaTextureAliases();
		if(isset($aliases[$name])){
			return $aliases[$name];
		}

		// Some deserialized block items don't retain ItemBlock at runtime.
		// Prefer known Bedrock block texture names before falling back to items/.
		$blockTextureNames = $this->vanillaBlockTextureNames();
		if($item instanceof ItemBlock || isset($blockTextureNames[$name])){
			return "textures/blocks/" . ($blockTextureNames[$name] ?? $name);
		}

		return "textures/items/" . $name;
	}

	private function normalizeTexturePath(string $icon, ?Item $item = null) : string{
		$icon = trim($icon);
		if($icon === ""){
			return "";
		}

		$lower = strtolower($icon);
		if(str_starts_with($lower, "minecraft:")){
			$icon = substr($icon, strlen("minecraft:"));
			$lower = strtolower($icon);
		}

		// If only an item identifier was entered in the Icon field, resolve it.
		if(!str_contains($icon, "/")){
			$short = preg_replace('/^textures[\\\/]*/i', '', $icon) ?? $icon;
			$short = trim($short);
			$aliases = $this->vanillaTextureAliases();
			if(isset($aliases[strtolower($short)])){
				return $aliases[strtolower($short)];
			}
			return $this->resolveIcon("", $item, $short);
		}

		$icon = preg_replace('#^textures/+#i', 'textures/', $icon) ?? $icon;
		$icon = preg_replace('#^items/+#i', 'textures/items/', $icon) ?? $icon;
		$icon = preg_replace('#^blocks/+#i', 'textures/blocks/', $icon) ?? $icon;

		// Bedrock vanilla special texture aliases.
		$base = strtolower(pathinfo($icon, PATHINFO_FILENAME));
		$aliases = $this->vanillaTextureAliases();
		if(isset($aliases[$base])){
			return $aliases[$base];
		}

		return $icon;
	}

	/** @return array<string, string> */
	private function vanillaBlockTextureNames() : array{
		return [
			'mangrove_roots' => 'mangrove_roots',
			'branching_mangrove_roots' => 'mangrove_roots',
			'muddy_mangrove_roots' => 'muddy_mangrove_roots',
			'grass_block' => 'grass_side',
			'dirt_path' => 'grass_path_side',
			'cobweb' => 'web',
			'iron_bars' => 'iron_bars',
			'glass_pane' => 'glass_pane_top',
		];
	}


	/**
	 * A focused alias table for the Bedrock names that commonly differ from
	 * PocketMine/Minecraft identifiers. Ordinary items fall back to the ID.
	 */
	private function vanillaTextureAliases() : array{
		return [
			'melon_slice' => 'textures/items/melon',
			'golden_apple' => 'textures/items/apple_golden',
			'cooked_beef' => 'textures/items/beef_cooked',
			'beef' => 'textures/items/beef_raw',
			'cooked_chicken' => 'textures/items/chicken_cooked',
			'chicken' => 'textures/items/chicken_raw',
			'cooked_mutton' => 'textures/items/mutton_cooked',
			'mutton' => 'textures/items/mutton_raw',
			'cooked_porkchop' => 'textures/items/porkchop_cooked',
			'porkchop' => 'textures/items/porkchop_raw',
			'cooked_rabbit' => 'textures/items/rabbit_cooked',
			'rabbit' => 'textures/items/rabbit_raw',
			'nautilus_shell' => 'textures/items/nautilus',
			'dragon_breath' => 'textures/items/dragons_breath',
			'iron_door' => 'textures/items/door_iron',
			'wooden_door' => 'textures/items/door_wood',
			'spruce_door' => 'textures/items/door_spruce',
			'birch_door' => 'textures/items/door_birch',
			'jungle_door' => 'textures/items/door_jungle',
			'acacia_door' => 'textures/items/door_acacia',
			'dark_oak_door' => 'textures/items/door_dark_oak',
			'golden_carrot' => 'textures/items/carrot_golden',
			'compass' => 'textures/items/compass_item',
			'clock' => 'textures/items/clock_item',
			'nether_brick' => 'textures/items/netherbrick',
			'beetroot_seeds' => 'textures/items/seeds_beetroot',
			'pumpkin_seeds' => 'textures/items/seeds_pumpkin',
			'melon_seeds' => 'textures/items/seeds_melon',
			'wheat_seeds' => 'textures/items/seeds_wheat',
			'spider_eye' => 'textures/items/spider_eye',
			'fermented_spider_eye' => 'textures/items/spider_eye_fermented',
			'fire_charge' => 'textures/items/fireworks_charge',
			'slime_ball' => 'textures/items/slimeball',
			'glowstone_dust' => 'textures/items/glowstone_dust',
			'redstone' => 'textures/items/redstone_dust',
			'repeater' => 'textures/items/repeater',
			'quartz' => 'textures/items/quartz',
			'prismarine_shard' => 'textures/items/prismarine_shard',
			'prismarine_crystals' => 'textures/items/prismarine_crystals',
			'heart_of_the_sea' => 'textures/items/heartofthesea_closed',
			'lodestone_compass' => 'textures/items/lodestonecompass_item',
			'firework_rocket' => 'textures/items/fireworks',
			'chainmail_helmet' => 'textures/items/chainmail_helmet',
			'chainmail_chestplate' => 'textures/items/chainmail_chestplate',
			'chainmail_leggings' => 'textures/items/chainmail_leggings',
			'chainmail_boots' => 'textures/items/chainmail_boots',
			'shield' => 'textures/entity/shield',
			'bow' => 'textures/items/bow_standby',
		];
	}

	/*
	 * =====================================================
	 * โหลดและบันทึกข้อมูล
	 * =====================================================
	 */

	private function load() : void{
		if(!is_file($this->dataFile)){
			$this->categories = $this->createDefaultCategories();
			$this->products = $this->createDefaultProducts();
			$this->save();
			return;
		}

		try{
			$raw = file_get_contents($this->dataFile);

			if($raw === false){
				throw new \RuntimeException("อ่าน shops.json ไม่ได้");
			}

			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

			$rawCategories = is_array($data["categories"] ?? null) ? $data["categories"] : [];
			$rawProducts = is_array($data["products"] ?? null)
				? $data["products"]
				: (is_array($data) ? $data : []);

			$this->categories = [];

			foreach($rawCategories as $rawCategory){
				if(is_array($rawCategory)){
					$name = trim((string) ($rawCategory["name"] ?? ""));

					if($name === ""){
						continue;
					}

					$this->categories[] = [
						"id" => trim((string) ($rawCategory["id"] ?? "")) !== ""
							? (string) $rawCategory["id"]
							: $this->makeUniqueCategoryId($name),
						"name" => $name,
						"emoji" => trim((string) ($rawCategory["emoji"] ?? "✨")),
						"icon" => trim((string) ($rawCategory["icon"] ?? ""))
					];
				}elseif(is_string($rawCategory) && trim($rawCategory) !== ""){
					$this->ensureCategory(trim($rawCategory), "✨", "");
				}
			}

			if(count($this->categories) === 0){
				$this->categories = $this->createDefaultCategories();
			}

			$this->products = [];

			foreach($rawProducts as $rawProduct){
				if(!is_array($rawProduct)){
					continue;
				}

				$product = $this->normalizeProduct($rawProduct);

				if($product !== null){
					$this->products[] = $product;
				}
			}

			$this->save();
		}catch(\Throwable $e){
			@rename($this->dataFile, $this->dataFile . ".broken-" . time());

			$this->plugin->getLogger()->warning(
				"shops.json ไม่ถูกต้อง สร้างใหม่: " . $e->getMessage()
			);

			$this->categories = $this->createDefaultCategories();
			$this->products = $this->createDefaultProducts();
			$this->save();
		}
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>|null
	 */
	private function normalizeProduct(array $raw) : ?array{
		$productId = $this->normalizeId((string) ($raw["id"] ?? ""));

		if($productId === ""){
			$productId = $this->makeUniqueId("shop");
		}

		$rawCategory = trim((string) ($raw["category"] ?? ""));
		$categoryId = "";

		if($rawCategory !== ""){
			if($this->getCategory($rawCategory) !== null){
				$categoryId = $rawCategory;
			}else{
				foreach($this->categories as $category){
					if((string) $category["name"] === $rawCategory){
						$categoryId = (string) $category["id"];
						break;
					}
				}

				if($categoryId === ""){
					$categoryId = $this->ensureCategory($rawCategory, "✨", "");
				}
			}
		}

		if($categoryId === ""){
			$categoryId = $this->ensureCategory("ไอเทมอื่น ๆ", "✨", "");
		}

		$identifier = trim((string) ($raw["base"] ?? $raw["item"] ?? ""));
		$itemNbt = trim((string) ($raw["item-nbt"] ?? ""));

		if($itemNbt === "" && $identifier === ""){
			return null;
		}

		if($itemNbt === "" && $this->parseItemIdentifier($identifier) === null){
			$this->plugin->getLogger()->warning("ข้ามสินค้า {$productId}: ไม่พบ {$identifier}");
			return null;
		}

		return [
			"id" => $productId,
			"category" => $categoryId,
			"name" => trim((string) ($raw["name"] ?? $productId)),
			"base" => $identifier,
			"amount" => max(1, (int) ($raw["amount"] ?? $raw["quantity"] ?? 1)),
			"buy" => max(0.0, round((float) ($raw["buy"] ?? 0), 2)),
			"sell" => max(0.0, round((float) ($raw["sell"] ?? 0), 2)),
			"icon" => trim((string) ($raw["icon"] ?? "")),
			"custom-name" => trim((string) ($raw["custom-name"] ?? "")),
			"custom-id" => trim((string) ($raw["custom-id"] ?? "")),
			"lore" => is_array($raw["lore"] ?? null) ? array_map("strval", $raw["lore"]) : [],
			"item-nbt" => $itemNbt
		];
	}

	private function save() : void{
		$json = json_encode(
			[
				"version" => 4,
				"categories" => array_values($this->categories),
				"products" => array_values($this->products)
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
			JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);

		$tempFile = $this->dataFile . ".tmp";

		if(file_put_contents($tempFile, $json, LOCK_EX) === false){
			throw new \RuntimeException("เขียน shops.json ไม่สำเร็จ");
		}

		if(!@rename($tempFile, $this->dataFile)){
			@unlink($this->dataFile);

			if(!@rename($tempFile, $this->dataFile)){
				throw new \RuntimeException("แทนที่ shops.json ไม่สำเร็จ");
			}
		}
	}

	private function ensureCategory(string $name, string $emoji, string $icon) : string{
		foreach($this->categories as $category){
			if((string) $category["name"] === $name){
				return (string) $category["id"];
			}
		}

		$id = $this->makeUniqueCategoryId($name);

		$this->categories[] = [
			"id" => $id,
			"name" => $name,
			"emoji" => $emoji !== "" ? $emoji : "✨",
			"icon" => $icon
		];

		return $id;
	}

	/** @return array<int, array<string, mixed>> */
	private function createDefaultCategories() : array{
		return [
			["id" => "blocks", "name" => "บล็อกก่อสร้าง", "emoji" => "🧱", "icon" => "textures/blocks/stone"],
			["id" => "weapons", "name" => "อาวุธ", "emoji" => "⚔️", "icon" => "textures/items/diamond_sword"],
			["id" => "tools", "name" => "เครื่องมือ", "emoji" => "⛏️", "icon" => "textures/items/diamond_pickaxe"],
			["id" => "armor", "name" => "ชุดเกราะ", "emoji" => "🛡️", "icon" => "textures/items/diamond_chestplate"],
			["id" => "food", "name" => "ผักและอาหาร", "emoji" => "🥕", "icon" => "textures/items/carrot"],
			["id" => "ores", "name" => "แร่และของมีค่า", "emoji" => "💎", "icon" => "textures/items/diamond"],
			["id" => "others", "name" => "ไอเทมอื่น ๆ", "emoji" => "✨", "icon" => ""]
		];
	}

	/** @return array<int, array<string, mixed>> */
	private function createDefaultProducts() : array{
		$defaults = [
			["stone", "blocks", "§fStone", "minecraft:stone", 64, 50.0, 20.0, "textures/blocks/stone"],
			["oak_planks", "blocks", "§6Oak Planks", "minecraft:oak_planks", 64, 80.0, 35.0, "textures/blocks/planks_oak"],
			["glass", "blocks", "§bGlass", "minecraft:glass", 64, 120.0, 50.0, "textures/blocks/glass"],
			["diamond_sword", "weapons", "§bDiamond Sword", "minecraft:diamond_sword", 1, 1500.0, 700.0, "textures/items/diamond_sword"],
			["bow", "weapons", "§6Bow", "minecraft:bow", 1, 750.0, 300.0, "textures/items/bow_standby"],
			["arrow", "weapons", "§fArrow", "minecraft:arrow", 32, 200.0, 80.0, "textures/items/arrow"],
			["iron_pickaxe", "tools", "§fIron Pickaxe", "minecraft:iron_pickaxe", 1, 700.0, 300.0, "textures/items/iron_pickaxe"],
			["diamond_pickaxe", "tools", "§bDiamond Pickaxe", "minecraft:diamond_pickaxe", 1, 1800.0, 800.0, "textures/items/diamond_pickaxe"],
			["diamond_axe", "tools", "§bDiamond Axe", "minecraft:diamond_axe", 1, 1700.0, 750.0, "textures/items/diamond_axe"],
			["diamond_helmet", "armor", "§bDiamond Helmet", "minecraft:diamond_helmet", 1, 2000.0, 900.0, "textures/items/diamond_helmet"],
			["diamond_chestplate", "armor", "§bDiamond Chestplate", "minecraft:diamond_chestplate", 1, 3000.0, 1400.0, "textures/items/diamond_chestplate"],
			["diamond_leggings", "armor", "§bDiamond Leggings", "minecraft:diamond_leggings", 1, 2800.0, 1300.0, "textures/items/diamond_leggings"],
			["diamond_boots", "armor", "§bDiamond Boots", "minecraft:diamond_boots", 1, 1800.0, 850.0, "textures/items/diamond_boots"],
			["carrot", "food", "§6Carrot", "minecraft:carrot", 16, 80.0, 35.0, "textures/items/carrot"],
			["bread", "food", "§eBread", "minecraft:bread", 16, 100.0, 40.0, "textures/items/bread"],
			["potato", "food", "§ePotato", "minecraft:potato", 16, 75.0, 30.0, "textures/items/potato"],
			["wheat", "food", "§eWheat", "minecraft:wheat", 32, 120.0, 50.0, "textures/items/wheat"],
			["golden_apple", "food", "§6Golden Apple", "minecraft:golden_apple", 1, 900.0, 400.0, "textures/items/apple_golden"],
			["diamond", "ores", "§bDiamond", "minecraft:diamond", 1, 500.0, 250.0, "textures/items/diamond"],
			["emerald", "ores", "§aEmerald", "minecraft:emerald", 1, 400.0, 180.0, "textures/items/emerald"],
			["iron_ingot", "ores", "§fIron Ingot", "minecraft:iron_ingot", 1, 150.0, 70.0, "textures/items/iron_ingot"],
			["gold_ingot", "ores", "§6Gold Ingot", "minecraft:gold_ingot", 1, 250.0, 110.0, "textures/items/gold_ingot"]
		];

		$result = [];

		foreach($defaults as $row){
			if($this->parseItemIdentifier($row[3]) === null){
				continue;
			}

			$result[] = [
				"id" => $row[0],
				"category" => $row[1],
				"name" => $row[2],
				"base" => $row[3],
				"amount" => $row[4],
				"buy" => $row[5],
				"sell" => $row[6],
				"icon" => $row[7],
				"custom-name" => "",
				"custom-id" => "",
				"lore" => [],
				"item-nbt" => ""
			];
		}

		return $result;
	}

	/*
	 * =====================================================
	 * Helper
	 * =====================================================
	 */

	private function makeUniqueId(string $base) : string{
		$base = $this->normalizeId($base);

		if($base === ""){
			$base = "shop";
		}

		if($this->getProduct($base) === null){
			return $base;
		}

		$index = 2;

		while($this->getProduct($base . "_" . $index) !== null){
			$index++;
		}

		return $base . "_" . $index;
	}

	private function makeUniqueCategoryId(string $name) : string{
		$base = $this->normalizeId($name);

		if($base === ""){
			$base = "cat_" . bin2hex(random_bytes(3));
		}

		if($this->getCategory($base) === null){
			return $base;
		}

		$index = 2;

		while($this->getCategory($base . "_" . $index) !== null){
			$index++;
		}

		return $base . "_" . $index;
	}

	private function normalizeId(string $value) : string{
		$value = strtolower(trim($value));
		$value = str_replace("minecraft:", "", $value);
		$value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? "";
		$value = trim($value, "_-");

		return substr($value, 0, 64);
	}

	private function parseInteger(string $value) : ?int{
		if(!is_numeric($value)){
			return null;
		}

		$number = (int) $value;

		return ($number >= 1 && $number <= 999) ? $number : null;
	}

	private function parsePrice(string $value, bool $allowZero = false) : ?float{
		if(!is_numeric($value)){
			return null;
		}

		$price = round((float) $value, 2);

		if(!is_finite($price)){
			return null;
		}

		if($allowZero){
			return $price >= 0 ? $price : null;
		}

		return $price > 0 ? $price : null;
	}

	private function money(float $amount) : string{
		return "§e" . number_format($amount, 2) . " §6⛃";
	}

	private function success(string $text) : string{
		return "§l§a✔ §f" . $text;
	}

	private function error(string $text) : string{
		return "§l§c✘ §f" . $text;
	}
}

/*
 * =========================================================
 * Form UI
 * =========================================================
 */

final class ShopSimpleForm implements Form{

	/** @var array<int, array<string, mixed>> */
	private array $buttons = [];

	/** @param \Closure(Player, int): void $callback */
	public function __construct(
		private string $title,
		private string $content,
		private \Closure $callback
	){}

	public function addButton(string $text, ?string $image = null) : self{
		$button = ["text" => $text];

		$image = trim((string) $image);

		if($image !== ""){
			$isUrl = str_starts_with($image, "https://") || str_starts_with($image, "http://");

			$button["image"] = [
				"type" => $isUrl ? "url" : "path",
				"data" => $image
			];
		}

		$this->buttons[] = $button;

		return $this;
	}

	public function handleResponse(Player $player, mixed $data) : void{
		if($data === null || !is_int($data) || !isset($this->buttons[$data])){
			return;
		}

		($this->callback)($player, $data);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->content,
			"buttons" => $this->buttons
		];
	}
}

final class ShopModalForm implements Form{

	/** @param \Closure(Player, bool): void $callback */
	public function __construct(
		private string $title,
		private string $content,
		private string $button1,
		private string $button2,
		private \Closure $callback
	){}

	public function handleResponse(Player $player, mixed $data) : void{
		if(!is_bool($data)){
			return;
		}

		($this->callback)($player, $data);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "modal",
			"title" => $this->title,
			"content" => $this->content,
			"button1" => $this->button1,
			"button2" => $this->button2
		];
	}
}

final class ShopCustomForm implements Form{

	/** @var array<int, array<string, mixed>> */
	private array $content = [];

	/** @param \Closure(Player, array): void $callback */
	public function __construct(
		private string $title,
		private \Closure $callback
	){}

	public function addLabel(string $text) : self{
		$this->content[] = [
			"type" => "label",
			"text" => $text
		];

		return $this;
	}

	public function addInput(
		string $text,
		string $placeholder = "",
		string $default = ""
	) : self{
		$this->content[] = [
			"type" => "input",
			"text" => $text,
			"placeholder" => $placeholder,
			"default" => $default
		];

		return $this;
	}

	/** @param array<int, string> $options */
	public function addDropdown(string $text, array $options, int $default = 0) : self{
		$this->content[] = [
			"type" => "dropdown",
			"text" => $text,
			"options" => array_values(array_map("strval", $options)),
			"default" => $default
		];

		return $this;
	}

	public function addToggle(string $text, bool $default = false) : self{
		$this->content[] = [
			"type" => "toggle",
			"text" => $text,
			"default" => $default
		];

		return $this;
	}

	public function addSlider(
		string $text,
		float $min,
		float $max,
		float $step = 1.0,
		?float $default = null
	) : self{
		$this->content[] = [
			"type" => "slider",
			"text" => $text,
			"min" => $min,
			"max" => $max,
			"step" => $step,
			"default" => $default ?? $min
		];

		return $this;
	}

	public function handleResponse(Player $player, mixed $data) : void{
		if(!is_array($data)){
			return;
		}

		($this->callback)($player, $data);
	}

	public function jsonSerialize() : mixed{
		return [
			"type" => "custom_form",
			"title" => $this->title,
			"content" => $this->content
		];
	}
}