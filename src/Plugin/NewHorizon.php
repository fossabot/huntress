<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class NewHorizon implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on("voiceStateUpdate", [self::class, "voiceStateHandler"]);
        $bot->client->on("guildMemberAdd", [self::class, "guildMemberAddHandler"]);
        $bot->client->on(self::PLUGINEVENT_COMMAND_PREFIX . "_NHInternalSetWelcomeMessage", [self::class, "setWelcome"]);
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "poll"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("nh_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);
    }

    /**
     * Adapted from Ligrev code by Christoph Burschka <christoph@burschka.de>
     */
    public static function poll(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function() use ($bot) {
            if (php_uname('s') == "Windows NT") {
                return null; // don't run on testing because oof
            }
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://ayin.earth/forum/index.php?action=.xml;type=rss2")->then(function(string $string) use ($bot) {

                $data     = \qp($string);
                $items    = $data->find('item');
                $lastPub  = self::getLastRSS();
                $newest   = $lastPub;
                $newItems = [];
                foreach ($items as $item) {
                    $published  = strtotime($item->find('pubDate')->text());
                    if ($published <= $lastPub || stripos($item->find('title')->text(), "Re:") === 0) // temporarily showing replies too :o
                        continue;
                    $newest     = max($newest, $published);
                    $newItems[] = (object) [
                        'title'    => $item->find('title')->text(),
                        'link'     => $item->find('link')->text(),
                        'date'     => (new \Carbon\Carbon($item->find('pubDate')->text())),
                        'category' => $item->find('category')->text(),
                        'body'     => (new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true]))->convert($item->find('description')->text()),
                    ];
                }
                foreach ($newItems as $item) {
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setColor(0xffd22b)->setFooter($item->category, "https://ayin.earth/img/nh3_s.png");
                    $bot->client->channels->get("479296410647527425")->send("", ['embed' => $embed]);
                }
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO nh_config (`key`, `value`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'integer']);
                $query->bindValue(1, "rssPublished");
                $query->bindValue(2, $newest);
                $query->execute();
            });
        });
    }

    public static function setWelcome(\Huntress\Bot $bot, \CharlotteDunois\Yasmin\Models\Message $message): \React\Promise\ExtendedPromiseInterface
    {
        if (is_null($message->member->roles->get("450658242125627402"))) {
            return self::unauthorized($message);
        } else {
            try {
                $args = self::_split($message->content);
                if (count($args) < 2) {
                    return self::error($message, "You dipshit :open_mouth:", "!_NHInternalSetWelcomeMessage This is where you put the message\n%s = username");
                }
                $welcomeMsg = trim(str_replace($args[0], "", $message->content));


                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO nh_config (`key`, `value`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'string']);
                $query->bindValue(1, "serverWelcomeMessage");
                $query->bindValue(2, $welcomeMsg);
                $query->execute();

                return self::send($message->channel, self::formatWelcomeMessage($message->author));
            } catch (\Throwable $e) {
                return self::exceptionHandler($message, $e, true);
            }
        }
    }

    public static function guildMemberAddHandler(\CharlotteDunois\Yasmin\Models\GuildMember $member): ?\React\Promise\ExtendedPromiseInterface
    {
        if ($member->guild->id != 450657331068403712) {
            return null;
        }
        return self::send($member->guild->channels->get("450691718359023616"), self::formatWelcomeMessage($member->user));
    }

    private static function formatWelcomeMessage(\CharlotteDunois\Yasmin\Models\User $member)
    {
        return sprintf(self::getWelcomeMessage(), (string) $member);
    }

    private static function getWelcomeMessage(): string
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'serverWelcomeMessage', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return "Welcome to New Horizon!";
    }

    private static function getLastRSS(): int
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("nh_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }

    public static function voiceStateHandler(\CharlotteDunois\Yasmin\Models\GuildMember $new, ?\CharlotteDunois\Yasmin\Models\GuildMember $old)
    {
        if ($new->guild->id == "450657331068403712" && $new->voiceChannel instanceof \CharlotteDunois\Yasmin\Models\VoiceChannel) {
            $role = $new->guild->roles->get("474058052916477955");
            if (is_null($new->roles->get("474058052916477955"))) {
                $new->addRole($role)->then(function () use ($new) {
                    self::send($new->guild->channels->get("468082034045222942"), "<@{$new->id}>, I'm going to give you the DJ role, since you're joining a voice chat.");
                });
            }
        }
    }
}
