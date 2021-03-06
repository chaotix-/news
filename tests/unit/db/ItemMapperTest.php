<?php
/**
 * ownCloud - News
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 */

namespace OCA\News\Db;


class ItemMapperTest extends  \Test\AppFramework\Db\MapperTestUtility {

    private $mapper;
    private $items;
    private $newestItemId;
    private $limit;
    private $user;
    private $offset;
    private $updatedSince;
    private $status;


    public function setUp() {
        parent::setup();

        $this->mapper = new ItemMapper($this->db);

        // create mock items
        $item1 = new Item();
        $item2 = new Item();

        $this->items = [
            $item1,
            $item2
        ];

        $this->userId = 'john';
        $this->id = 3;
        $this->folderId = 2;

        $this->row = [
            ['id' => $this->items[0]->getId()],
        ];

        $this->rows = [
            ['id' => $this->items[0]->getId()],
            ['id' => $this->items[1]->getId()]
        ];

        $this->user = 'john';
        $this->limit = 10;
        $this->offset = 3;
        $this->id = 11;
        $this->status = 333;
        $this->updatedSince = 323;
        $this->newestItemId = 2;

    }


    private function makeSelectQuery($prependTo, $oldestFirst=false){
        if ($oldestFirst) {
            $ordering = 'ASC';
        } else {
            $ordering = 'DESC';
        }

        return 'SELECT `items`.* FROM `*PREFIX*news_items` `items` '.
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` '.
                'AND `feeds`.`deleted_at` = 0 ' .
                'AND `feeds`.`user_id` = ? ' .
                $prependTo .
            'LEFT OUTER JOIN `*PREFIX*news_folders` `folders` ' .
                'ON `folders`.`id` = `feeds`.`folder_id` ' .
            'WHERE `feeds`.`folder_id` = 0 ' .
                'OR `folders`.`deleted_at` = 0 ' .
            'ORDER BY `items`.`id` ' . $ordering;
    }

    private function makeSelectQueryStatus($prependTo, $status,
                                           $oldestFirst=false) {
        $status = (int) $status;

        return $this->makeSelectQuery(
            'AND ((`items`.`status` & ' . $status . ') = ' . $status . ') ' .
            $prependTo, $oldestFirst
        );
    }


    public function testFind(){
        $sql = $this->makeSelectQuery('AND `items`.`id` = ? ');

        $this->setMapperResult(
            $sql, [$this->userId, $this->id], $this->row
        );

        $result = $this->mapper->find($this->id, $this->userId);
        $this->assertEquals($this->items[0], $result);
    }


    public function testGetStarredCount(){
        $userId = 'john';
        $row = [['size' => 9]];
        $sql = 'SELECT COUNT(*) AS size FROM `*PREFIX*news_items` `items` '.
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` '.
                'AND `feeds`.`deleted_at` = 0 ' .
                'AND `feeds`.`user_id` = ? ' .
                'AND ((`items`.`status` & ' . StatusFlag::STARRED . ') = ' .
                StatusFlag::STARRED . ')' .
            'LEFT OUTER JOIN `*PREFIX*news_folders` `folders` ' .
                'ON `folders`.`id` = `feeds`.`folder_id` ' .
            'WHERE `feeds`.`folder_id` = 0 ' .
                'OR `folders`.`deleted_at` = 0';

        $this->setMapperResult($sql, [$userId], $row);

        $result = $this->mapper->starredCount($userId);
        $this->assertEquals($row[0]['size'], $result);
    }


    public function testReadAll(){
        $sql = 'UPDATE `*PREFIX*news_items` ' .
            'SET `status` = `status` & ? ' .
            ', `last_modified` = ? ' .
            'WHERE `feed_id` IN (' .
                'SELECT `id` FROM `*PREFIX*news_feeds` ' .
                    'WHERE `user_id` = ? ' .
                ') '.
            'AND `id` <= ?';
        $params = [~StatusFlag::UNREAD, $this->updatedSince, $this->user, 3];
        $this->setMapperResult($sql, $params);
        $this->mapper->readAll(3, $this->updatedSince, $this->user);
    }


    public function testReadFolder(){
        $sql = 'UPDATE `*PREFIX*news_items` ' .
            'SET `status` = `status` & ? ' .
            ', `last_modified` = ? ' .
            'WHERE `feed_id` IN (' .
                'SELECT `id` FROM `*PREFIX*news_feeds` ' .
                    'WHERE `folder_id` = ? ' .
                    'AND `user_id` = ? ' .
                ') '.
            'AND `id` <= ?';
        $params = [~StatusFlag::UNREAD, $this->updatedSince, 3, $this->user, 6];
        $this->setMapperResult($sql, $params);
        $this->mapper->readFolder(3, 6, $this->updatedSince, $this->user);
    }


    public function testReadFeed(){
        $sql = 'UPDATE `*PREFIX*news_items` ' .
            'SET `status` = `status` & ? ' .
            ', `last_modified` = ? ' .
                'WHERE `feed_id` = ? ' .
                'AND `id` <= ? ' .
                'AND EXISTS (' .
                    'SELECT * FROM `*PREFIX*news_feeds` ' .
                    'WHERE `user_id` = ? ' .
                    'AND `id` = ? ) ';
        $params = [
            ~StatusFlag::UNREAD, $this->updatedSince, 3, 6, $this->user, 3
        ];
        $this->setMapperResult($sql, $params);
        $this->mapper->readFeed(3, 6, $this->updatedSince, $this->user);
    }


    public function testFindAllNew(){
        $sql = 'AND `items`.`last_modified` >= ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->updatedSince];

        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllNew($this->updatedSince,
            $this->status, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllNewFolder(){
        $sql = 'AND `feeds`.`folder_id` = ? ' .
                'AND `items`.`last_modified` >= ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);

        $params = [$this->user, $this->id, $this->updatedSince];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllNewFolder($this->id,
            $this->updatedSince, $this->status, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllNewFeed(){
        $sql = 'AND `items`.`feed_id` = ? ' .
                'AND `items`.`last_modified` >= ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->id, $this->updatedSince];

        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllNewFeed($this->id, $this->updatedSince,
            $this->status, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllUnreadOrStarred(){
        $status = StatusFlag::UNREAD | StatusFlag::STARRED;
        $sql = 'AND ((`items`.`status` & ' . $status . ') > 0) ';
        $sql = $this->makeSelectQuery($sql);
        $params = [$this->user];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllUnreadOrStarred($this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFeed(){
        $sql = 'AND `items`.`feed_id` = ? ' .
            'AND `items`.`id` < ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->id, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFeed($this->id, $this->limit,
                $this->offset, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFeedOldestFirst(){
        $sql = 'AND `items`.`feed_id` = ? ' .
            'AND `items`.`id` > ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status, true);
        $params = [$this->user, $this->id, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFeed($this->id, $this->limit,
                $this->offset, $this->status, true, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFeedOffsetZero(){
        $sql = 'AND `items`.`feed_id` = ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->id];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFeed($this->id, $this->limit,
                0, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFolder(){
        $sql = 'AND `feeds`.`folder_id` = ? ' .
            'AND `items`.`id` < ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->id, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFolder($this->id, $this->limit,
                $this->offset, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFolderOldestFirst(){
        $sql = 'AND `feeds`.`folder_id` = ? ' .
            'AND `items`.`id` > ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status, true);
        $params = [$this->user, $this->id, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFolder($this->id, $this->limit,
                $this->offset, $this->status, true, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllFolderOffsetZero(){
        $sql = 'AND `feeds`.`folder_id` = ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->id];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAllFolder($this->id, $this->limit,
                0, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAll(){
        $sql = 'AND `items`.`id` < ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status);
        $params = [$this->user, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAll($this->limit,
                $this->offset, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllOldestFirst(){
        $sql = 'AND `items`.`id` > ? ';
        $sql = $this->makeSelectQueryStatus($sql, $this->status, true);
        $params = [$this->user, $this->offset];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAll($this->limit,
                $this->offset, $this->status, true, $this->user);

        $this->assertEquals($this->items, $result);
    }


    public function testFindAllOffsetZero(){
        $sql = $this->makeSelectQueryStatus('', $this->status);
        $params = [$this->user];
        $this->setMapperResult($sql, $params, $this->rows);
        $result = $this->mapper->findAll($this->limit,
                0, $this->status, false, $this->user);

        $this->assertEquals($this->items, $result);
    }




    public function testFindByGuidHash(){
        $hash = md5('test');
        $feedId = 3;
        $sql = $this->makeSelectQuery(
            'AND `items`.`guid_hash` = ? ' .
            'AND `feeds`.`id` = ? ');

        $this->setMapperResult(
            $sql, [$this->userId, $hash, $feedId], $this->row);

        $result = $this->mapper->findByGuidHash($hash, $feedId, $this->userId);
        $this->assertEquals($this->items[0], $result);
    }


    public function testDeleteReadOlderThanThresholdDoesNotDelete(){
        $status = StatusFlag::STARRED | StatusFlag::UNREAD;
        $sql =  'SELECT (COUNT(*) - `feeds`.`articles_per_update`) AS `size`' .
        ', `feeds`.`id` AS `feed_id`, `feeds`.`articles_per_update` ' .
            'FROM `*PREFIX*news_items` `items` ' .
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` ' .
            'AND NOT ((`items`.`status` & ?) > 0) ' .
            'GROUP BY `feeds`.`id`, `feeds`.`articles_per_update` ' .
            'HAVING COUNT(*) > ?';

        $threshold = 10;
        $rows = [['feed_id' => 30, 'size' => 9]];
        $params = [$status, $threshold];

        $this->setMapperResult($sql, $params, $rows);
        $this->mapper->deleteReadOlderThanThreshold($threshold);


    }


    public function testDeleteReadOlderThanThreshold(){
        $threshold = 10;
        $status = StatusFlag::STARRED | StatusFlag::UNREAD;

        $sql1 = 'SELECT (COUNT(*) - `feeds`.`articles_per_update`) AS `size`' .
        ', `feeds`.`id` AS `feed_id`, `feeds`.`articles_per_update` ' .
            'FROM `*PREFIX*news_items` `items` ' .
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` ' .
                'AND NOT ((`items`.`status` & ?) > 0) ' .
            'GROUP BY `feeds`.`id`, `feeds`.`articles_per_update` ' .
            'HAVING COUNT(*) > ?';
        $params1 = [$status, $threshold];

        $sql2 = 'DELETE FROM `*PREFIX*news_items` ' .
                'WHERE `id` IN (' .
                    'SELECT `id` FROM `*PREFIX*news_items` ' .
                    'WHERE NOT ((`status` & ?) > 0) ' .
                    'AND `feed_id` = ? ' .
                    'ORDER BY `id` ASC ' .
                    'LIMIT ?' .
                ')';
        $params2 = [$status, 30, 1];

        $row = ['feed_id' => 30, 'size' => 11];
        $this->setMapperResult($sql1, $params1, [$row]);
        $this->setMapperResult($sql2, $params2);

        $this->mapper->deleteReadOlderThanThreshold($threshold);
    }


    public function testGetNewestItem() {
        $sql = 'SELECT MAX(`items`.`id`) AS `max_id` ' .
            'FROM `*PREFIX*news_items` `items` '.
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` '.
                'AND `feeds`.`user_id` = ?';
        $params = [$this->user];
        $rows = [['max_id' => 3]];

        $this->setMapperResult($sql, $params, $rows);

        $result = $this->mapper->getNewestItemId($this->user);
        $this->assertEquals(3, $result);
    }


    public function testGetNewestItemIdNotFound() {
        $sql = 'SELECT MAX(`items`.`id`) AS `max_id` ' .
            'FROM `*PREFIX*news_items` `items` '.
            'JOIN `*PREFIX*news_feeds` `feeds` ' .
                'ON `feeds`.`id` = `items`.`feed_id` '.
                'AND `feeds`.`user_id` = ?';
        $params = [$this->user];
        $rows = [];

        $this->setMapperResult($sql, $params, $rows);
        $this->setExpectedException(
            '\OCP\AppFramework\Db\DoesNotExistException'
        );

        $this->mapper->getNewestItemId($this->user);
    }


    public function testDeleteFromUser(){
        $userId = 'john';
        $sql = 'DELETE FROM `*PREFIX*news_items` ' .
            'WHERE `feed_id` IN (' .
                'SELECT `feeds`.`id` FROM `*PREFIX*news_feeds` `feeds` ' .
                    'WHERE `feeds`.`user_id` = ?' .
                ')';

        $this->setMapperResult($sql, [$userId]);

        $this->mapper->deleteUser($userId);
    }


}