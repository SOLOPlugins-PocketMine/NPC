<?php

namespace onebone\npc;

use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\inventory\EntityInventory;
use pocketmine\tile\Chest as TileChest;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\BlockEntityDataPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\types\ContainerIds;
use pocketmine\network\mcpe\protocol\types\WindowTypes;

class NPCInventory extends EntityInventory{

	public function __construct(NPC $npc){
		parent::__construct($npc);
	}

	public function getNetworkType() : int{
		return WindowTypes::CONTAINER;
	}

	public function getName() : string{
		return $this->holder->getNameTag();
	}

	public function getDefaultSize() : int{
		return 5; // item in hand 1 + armor 4
	}

	public function setItemInHand(Item $item){
		return $this->setItem(0, $item);
	}

	public function sendHeldItem($target){
		if($target instanceof Player){
			$target = [$target];
		}
		$item = $this->getItemInHand();

		$pk = new MobEquipmentPacket();
		$pk->entityRuntimeId = $this->getHolder()->getId();
		$pk->item = $item;
		$pk->inventorySlot = 0;
		$pk->windowId = ContainerIds::INVENTORY;

		$this->getHolder()->getLevel()->getServer()->broadcastPacket($target, $pk);
	}

	public function getItemInHand(){
		return $this->getItem(0);
	}

	public function getHelmet(){
		return $this->getItem(1);
	}

	public function getChestplate(){
		return $this->getItem(2);
	}

	public function getLeggings(){
		return $this->getItem(3);
	}

	public function getBoots(){
		return $this->getItem(4);
	}

	public function setHelmet(Item $helmet){
		return $this->setItem(1, $helmet);
	}

	public function setChestplate(Item $chestplate){
		return $this->setItem(2, $chestplate);
	}

	public function setLeggings(Item $leggings){
		return $this->setItem(3, $leggings);
	}

	public function setBoots(Item $boots){
		return $this->setItem(4, $boots);
	}

	public function getArmorContents(){
		$armor = [];
		for($i = 0; $i < 4; ++$i){
			$armor[$i] = $this->getItem(1 + $i);
		}
		return $armor;
	}

	public function sendArmorContents($target){
		if($target instanceof Player){
			$target = [$target];
		}

		$armor = $this->getArmorContents();

		$pk = new MobArmorEquipmentPacket();
		$pk->entityRuntimeId = $this->getHolder()->getId();
		$pk->slots = $armor;
		$pk->encode();

		foreach($target as $player){
			$player->dataPacket($pk);
		}
	}

	public function setArmorContents(array $items){
		for($i = 0; $i < 4; ++$i){
			if(!isset($items[$i]) || !($items[$i] instanceof Item)){
				$items[$i] = Item::get(Item::AIR, 0, 0);
			}
			$this->setItem(1 + $i, $items[$i], false);
		}
		$this->sendArmorContents($this->getViewers());
	}

	public function sendContents($target) : void{
		parent::sendContents($target);
		//if($target instanceof Player){
		//	$target = [$target];
		//}
		//$pk = new InventoryContentPacket();

		//$originSize = $this->getSize();
		//$actualSize = ceil($originSize / 27) * 27;
		//for($i = 0; $i < $originSize; ++$i){
		//	$pk->items[$i] = $this->getItem($i);
		//}
		//for($i = $originSize; $i < $actualSize; ++$i){
		//	$pk->items[$i] = Item::get(160, 14);
		//}
		//foreach($target as $player){
		//	if(($id = $player->getWindowId($this)) === ContainerIds::NONE){
		//		$this->close($player);
		//		continue;
		//	}
		//	$pk->windowId = $id;
		//	$player->dataPacket($pk);
		//}

		$this->sendHeldItem($target);
		$this->sendArmorContents($target);
	}


	private $previousBlocks = [];

	public function onOpen(Player $who) : void{
		parent::onOpen($who);

		$fakeVector = $who->getDirectionVector()->multiply(-2)->floor();
		$this->previousBlocks[$who->getId()] = $who->getLevel()->getBlock($fakeVector);

		$fakeBlock = Block::get(Block::CHEST);
		$fakeBlock->x = $fakeVector->getX();
		$fakeBlock->y = $fakeVector->getY();
		$fakeBlock->z = $fakeVector->getZ();
		$fakeBlock->level = $who->level;
		$fakeBlock->level->sendBlocks([$who], [$fakeBlock]);

		$fakeTile = TileChest::createTile("Chest", $who->level, TileChest::createNBT($fakeVector));
		$fakeTile->spawnTo($who);

		/*
		$pk = new UpdateBlockPacket();
		$pk->x = $who->getFloorX();
		$pk->y = $who->getFloorY() - 2;
		$pk->z = $who->getFloorZ();
		$pk->blockId = Block::CHEST;
		$pk->blockData = 0;
		$pk->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
		$who->dataPacket($pk);

		$pk = new BlockEntityDataPacket();
		$pk->x = $who->getFloorX();
		$pk->y = $who->getFloorY() - 2;
		$pk->z = $who->getFloorZ();
		$namedtag = TileChest::createNBT(new Vector3($pk->x, $pk->y, $pk->z));
		$namedtag->CustomName = new StringTag("CustomName", $this->holder->getNameTag());
		$nbt = new NBT(NBT::LITTLE_ENDIAN);
		$nbt->setData($namedtag);
		$pk->namedtag = $nbt->write(true);
		$who->dataPacket($pk);
		*/

		$pk = new ContainerOpenPacket();
		$pk->windowId = $who->getWindowId($this);
		$pk->type = WindowTypes::CONTAINER;
		$pk->x = $fakeVector->getX();
		$pk->y = $fakeVector->getY();
		$pk->z = $fakeVector->getZ();
		$who->dataPacket($pk);

		$this->sendContents($who);
	}

	public function onClose(Player $who) : void{
		$pk = new ContainerClosePacket();
		$pk->windowId = $who->getWindowId($this);
		$who->dataPacket($pk);

		if(isset($this->previousBlocks[$who->getId()])){
			$previousBlock = $this->previousBlocks[$who->getId()];
			$previousBlock->getLevel()->sendBlocks([$who], [$previousBlock]);
		}

		$this->sendHeldItem($who);
		$this->sendArmorContents($who);
		parent::onClose($who);
	}
}