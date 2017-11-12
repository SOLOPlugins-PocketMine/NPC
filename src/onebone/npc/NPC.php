<?php

/*
 * Copyright (C) 2015-2016 onebone <jyc00410@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace onebone\npc;

use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\level\Level;
use pocketmine\item\Item;
use pocketmine\inventory\InventoryHolder;
use pocketmine\math\Vector2;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\MoveEntityPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\RemoveEntityPacket;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\utils\UUID;

class NPC extends Entity implements InventoryHolder{

	private static $queue = [];

	public static function setQueue(Player $player, callable $func){
		self::$queue[$player->getId()] = $func;
	}

	public static function getQueue(Player $player){
		return self::$queue[$player->getId()] ?? null;
	}

	public static function removeQueue(Player $player){
		if(isset(self::$queue[$player->getId()])){
			unset(self::$queue[$player->getId()]);
			return true;
		}
		return false;
	}



	/** @var UUID */
	private $uuid;

	/** @var Skin */
	private $skin;

	/** @var NPCInventory */
	private $inventory;

	/** @var string */
	private $message;

	/** @var string */
	private $command;

	protected function initEntity(){
		$this->uuid = UUID::fromRandom();

		$this->setGenericFlag(self::DATA_FLAG_ALWAYS_SHOW_NAMETAG, true);
		$this->setGenericFlag(self::DATA_FLAG_CAN_SHOW_NAMETAG, true);

		$this->inventory = new NPCInventory($this);
		if(isset($this->namedtag->Inventory) && $this->namedtag->Inventory instanceof ListTag){
			foreach($this->namedtag->Inventory as $i => $item){
				$this->inventory->setItem($item["Slot"], Item::nbtDeserialize($item));
			}
		}

		parent::initEntity();

		if(isset($this->namedtag->Skin)){
			$this->setSkin(new Skin(
				$this->namedtag->Skin["Name"] ?? "",
				$this->namedtag->Skin["Data"] ?? ""
			));
		}
		isset($this->namedtag->Message) ? $this->message = $this->namedtag["Message"] : $this->message = "";
		isset($this->namedtag->Command) ? $this->command = $this->namedtag["Command"] : $this->command = "";

	}

	public function setMessage(string $message){
		$this->message = $message;
	}

	public function getMessage(){
		return $this->message;
	}

	public function setCommand(string $command){
		$this->command = $command;
	}

	public function getCommand(){
		return $this->command;
	}

	public function getInventory(){
		return $this->inventory;
	}

	public function setSkin(Skin $skin){
		if(!$skin->isValid()){
			throw new \InvalidStateException("Specified skin is not valid, must be 8KiB or 16 KiB");
		}
		$this->skin = $skin;
	}

	public function sendSkin(array $targets){
		$pk = new PlayerSkinPacket();
		$pk->uuid = $this->uuid;
		$pk->skin = $this->skin;
		$this->server->broadcastPacket($targets, $pk);
	}

	public function getSkin(){
		return $this->skin;
	}

	public function attack(EntityDamageEvent $source){
		if($source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Player){
			$func = self::getQueue($source->getDamager());
			if($func !== null){
				$func($this);
			}else{
				if($this->message !== ""){
					$source->getDamager()->sendMessage($this->message);
				}
				if($this->command !== ""){
					$this->server->dispatchCommand($source->getDamager(), $this->command);
				}
			}
		}
	}

	public function onUpdate(int $currentTick) : bool{
		//$this->lastUpdate = $currentTick;

		//$this->timings->startTiming();

		//$distance = 4;

		//$minX = intval(floor(($this->x - $distance) / 16));
		//$minZ = intval(floor(($this->z - $distance) / 16));
		//$maxX = intval(ceil(($this->x + $distance) / 16));
		//$maxZ = intval(ceil(($this->z + $distance) / 16));
		//for($x = $minX; $x <= $maxX; ++$x){
		//	for($z = $minZ; $z <= $maxZ; ++$z){
		//		foreach($this->getLevel()->getChunkEntities($x, $z) as $target){
		//			if($target instanceof Player && $target->distance($this) < $distance){
		//				$pk = new MoveEntityPacket();
		//				$pk->entityRuntimeId = $this->id;
		//				$pk->position = $this->getOffsetPosition($this);
		//				$pk->pitch = (atan2((new Vector2($this->x, $this->z))->distance($target->x, $target->z), $target->y + $target->getEyeHeight() - $this->y) * 180 / M_PI) - 90;
		//				$pk->yaw = $pk->headYaw = (atan2($target->z - $this->z, $target->x - $this->x) * 180 / M_PI) - 90;
		//				$target->dataPacket($pk);
		//			}
		//		}
		//	}
		//}

		//$this->timings->stopTiming();

		return false; // do not tick NPC
	}

	public function saveNBT(){
		parent::saveNBT();

		$this->namedtag->Message = new StringTag("Message", $this->message);
		$this->namedtag->Command = new StringTag("Command", $this->command);

		$this->namedtag->Inventory = new ListTag("Inventory", [], NBT::TAG_Compound);
		if($this->inventory !== null){
			$slotCount = $this->inventory->getSize();
			for($slot = 0; $slot < $slotCount; ++$slot){
				$item = $this->inventory->getItem($slot);
				if($item->getId() !== Item::AIR){
					$this->namedtag->Inventory[$slot] = $item->nbtSerialize($slot);
				}
			}
		}

		if($this->skin !== null){
			$this->namedtag->Skin = new CompoundTag("Skin", [
				new StringTag("Data", $this->skin->getSkinData()),
				new StringTag("Name", $this->skin->getSkinId())
			]);
		}
	}

	protected function sendSpawnPacket(Player $player) : void{
		$pk = new AddPlayerPacket();
		$pk->uuid = $this->uuid;
		$pk->username = $this->getNameTag();
		$pk->entityRuntimeId = $this->getId();
		$pk->position = $this->asVector3();
		$pk->pitch = $this->pitch;
		$pk->yaw = $this->yaw;
		$pk->item = $this->inventory->getItemInHand();
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		if($this->skin !== null || $this->skin->isValid()){
			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_ADD;
			$pk->entries[] = PlayerListEntry::createAdditionEntry($this->uuid, $this->id, "§7[NPC] " . $this->getNameTag(), $this->skin);
			$player->dataPacket($pk);
		}

		$this->inventory->sendArmorContents($player);
	}

	public function despawnFrom(Player $player, bool $send = true){$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_REMOVE;
		$pk->entries[] = PlayerListEntry::createRemovalEntry($this->uuid);
		$player->dataPacket($pk);

		parent::despawnFrom($player, $send);
	}
}
