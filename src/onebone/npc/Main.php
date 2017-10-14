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
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\entity\Entity;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;


class Main extends PluginBase{

	public static $prefix = "§b§l[NPC] §r§7";

	public function onLoad(){
		Entity::registerEntity(NPC::class, true);
	}

	public function onEnable(){

	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $params) : bool{
		switch(strtolower(array_shift($params))){
			case "create":
			case "c":
				if(!$sender instanceof Player){
					$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
					return true;
				}
				if(!$sender->hasPermission("npc.command.npc.create")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}
				$name = implode(" ", $params);
				if(trim($name) === ""){
					$sender->sendMessage(Main::$prefix . "사용법 : /npc create <name> - NPC를 생성합니다.");
					return true;
				}

				$npc = Entity::createEntity("NPC", $sender->getLevel(), new CompoundTag("", [
					new ListTag("Pos", [
						new DoubleTag("", $sender->x),
						new DoubleTag("", $sender->y),
						new DoubleTag("", $sender->z)
					]),
					new ListTag("Motion", [
						new DoubleTag("", 0),
						new DoubleTag("", 0),
						new DoubleTag("", 0)
					]),
					new ListTag("Rotation", [
						new FloatTag("", $sender->yaw),
						new FLoatTag("", $sender->pitch)
					])
				]));

				$npc->setNameTag($name);
				$npc->setRotation($sender->yaw, $sender->pitch);
				$npc->setSkin(clone $sender->getSkin());
				$npc->getInventory()->setItemInHand($sender->getInventory()->getItemInHand());
				$npc->getInventory()->setArmorContents($sender->getInventory()->getArmorContents());
				$npc->spawnToAll();

				$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 을(를) 생성하였습니다.");
				break;

			case "remove":
			case "r":
				if(!$sender->hasPermission("npc.command.npc.remove")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}
				if(!empty($params)){
					$id = array_shift($params);
					if(!preg_match("/[0-9]+/", $id)){
						$sender->sendMessage(Main::$prefix . "사용법 : /npc remove [id] - 해당 id의 NPC를 삭제합니다. 인자를 입력하지 않을 시, 터치하여 NPC를 삭제할 수 있습니다.");
						return true;
					}
					$npc = $this->getServer()->findEntity($id);
					if(!$npc instanceof NPC){
						$sender->sendMessage(Main::$prefix . "해당 id의 NPC를 찾을 수 없습니다.");
						return true;
					}
					$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 을(를) 제거하였습니다.");
					$npc->close();

				}else{
					if(!$sender instanceof Player){
						$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
						return true;
					}
					$sender->sendMessage(Main::$prefix . "제거할 NPC를 터치해주세요.");

					NPC::setQueue($sender, function(NPC $npc) use($sender){
						$npc->close();
						$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 을(를) 제거하였습니다.");
						NPC::removeQueue($sender);
					});
				}
				break;

			case "list":
			case "ls":
			case "l":
				if(!$sender->hasPermission("npc.command.npc.list")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}

				$npcList = array_merge(...array_map(function($level){ return array_filter($level->getEntities(), function($entity){ return $entity instanceof NPC; }); }, $this->getServer()->getLevels()));

				$page = array_shift($params);
				if(!preg_match("/[0-9]+/", $page)){
					$page = 1;
				}
				$max = ceil(count($npcList)/5);
				$page = (int)$page;
				$page = max(1, min($page, $max));

				$output = "§l==========[ NPC 목록 (전체 " . $max . "페이지 중 " . $page . "페이지) ]==========§r\n";
				$n = 0;

				foreach($npcList as $npc){
					$current = (int)ceil(++$n / 5);

					if($current === $page){
						$output .= "§7["
							. $npc->getLevel()->getName() . "."
							. round($npc->x, 2) . "."
							. round($npc->y, 2) . "."
							. round($npc->z, 2) . "."
						. "]"
						. " NPC 이름 : " . $npc->getNameTag()
						. ", NPC ID : " . $npc->getId()
						. "\n";
					}elseif($current > $page) break;
				}
				$output = substr($output, 0, -1);
				$sender->sendMessage($output);
				break;

			case "message":
			case "msg":
			case "m":
				if(!$sender instanceof Player){
					$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
					return true;
				}
				if(!$sender->hasPermission("npc.command.npc.message")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}
				$message = trim(implode(" ", $params));
				$sender->sendMessage(Main::$prefix . "메세지를 변경할 NPC를 터치하세요.");

				NPC::setQueue($sender, function(NPC $npc) use($sender, $message){
					$npc->setMessage($message);
					$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 의 메세지를 \"" . $message . "\" (으)로 변경하였습니다.");
					NPC::removeQueue($sender);
				});
				break;

			case "command":
			case "cmd":
				if(!$sender instanceof Player){
					$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
					return true;
				}
				if(!$sender->hasPermission("npc.command.npc.command")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}
				$command = trim(implode(" ", $params));
				$sender->sendMessage(Main::$prefix . "명령어를 변경할 NPC를 터치하세요.");

				NPC::setQueue($sender, function(NPC $npc) use($sender, $command){
					$npc->setCommand($command);
					$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 의 명령어를 \"" . $command . "\" (으)로 변경하였습니다.");
					NPC::removeQueue($sender);
				});
				break;

			// Open NPC's Inventory, But not implemented completely
			//
			//case "inventory":
			//case "inven":
			//case "i":
			//	if(!$sender instanceof Player){
			//		$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
			//		return true;
			//	}
			//	if(!$sender->hasPermission("npc.command.npc.inventory")){
			//		$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
			//		return true;
			//	}
			//	$sender->sendMessage(Main::$prefix . "인벤토리를 편집할 NPC를 터치하세요.");
			//	NPC::setQueue($sender, function(NPC $npc) use($sender){
			//		$sender->addWindow($npc->getInventory());
			//		NPC::removeQueue($sender);
			//	});
			//	break;

			case "change":
				if(!$sender instanceof Player){
					$sender->sendMessage(Main::$prefix . "인게임에서만 사용 가능합니다.");
					return true;
				}
				if(!$sender->hasPermission("npc.command.npc.skin")){
					$sender->sendMessage(Main::$prefix . "이 명령을 사용할 권한이 없습니다.");
					return true;
				}

				$sender->sendMessage(Main::$prefix . "모습을 변경할 NPC를 터치하세요.");
				NPC::setQueue($sender, function(NPC $npc) use($sender){
					$npc->getInventory()->setItemInHand($sender->getInventory()->getItemInHand());
					$npc->getInventory()->setArmorContents($sender->getInventory()->getArmorContents());
					$npc->setSkin(clone $sender->getSkin());

					$npc->getInventory()->sendHeldItem($npc->getViewers());
					$npc->getInventory()->sendArmorContents($npc->getViewers());
					$npc->sendSkin($npc->getViewers());
					$sender->sendMessage(Main::$prefix . "NPC \"" . $npc->getNameTag() . "\" 의 모습을 변경하였습니다.");
					NPC::removeQueue($sender);
				});
				break;

			default:
				$sender->sendMessage(Main::$prefix . "사용법 : /npc <create|list|remove|message|command|change>");
		}
		return true;
	}
}
