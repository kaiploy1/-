<?php
// ตัวอย่างดักจับ Event จากปลั๊กอินอื่น

namespace MyServer\OtherPlugin;

use MyServer\SimpleMoney\event\BankTransactionEvent;
use MyServer\SimpleMoney\event\MoneyTransactionEvent;
use MyServer\SimpleMoney\event\ShopTransactionEvent;
use pocketmine\event\Listener;

final class EconomyListener implements Listener{

	public function onMoneyTransaction(MoneyTransactionEvent $event) : void{
		// ยกเลิกการโอนเงินเกิน 100,000
		if($event->getType() === "transfer" && $event->getAmount() > 100000.0){
			$event->setCancelled();
		}
	}

	public function onBankTransaction(BankTransactionEvent $event) : void{
		// ตัวอย่าง: เพิ่มดอกเบี้ยเป็น 2 เท่า
		if($event->getType() === "interest"){
			$event->setAmount($event->getAmount() * 2);
		}
	}

	public function onShopTransaction(ShopTransactionEvent $event) : void{
		// ตัวอย่าง: ปิดการซื้อ Diamond จากร้าน
		if($event->getType() === "buy" && $event->getShopItemId() === "diamond"){
			$event->setCancelled();
		}
	}
}