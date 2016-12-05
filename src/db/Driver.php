<?php

namespace zhuravljov\yii\queue\db;

use yii\base\BootstrapInterface;
use yii\db\Connection;
use yii\db\Query;
use yii\di\Instance;
use yii\mutex\Mutex;
use zhuravljov\yii\queue\Driver as BaseDriver;

/**
 * Class DbDriver
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class Driver extends BaseDriver implements BootstrapInterface
{
    /**
     * @var Connection|array|string
     */
    public $db = 'db';
    /**
     * @var Mutex|array|string
     */
    public $mutex = 'mutex';
    /**
     * @var string table name
     */
    public $tableName = '{{%queue}}';
    /**
     * @var boolean ability to delete released messages from table
     */
    public $deleteReleased = false;

    /**
     * @inheritdoc
     */
    public function push($channel, $job)
    {
        $this->db->createCommand()->insert($this->tableName, [
            'channel' => $channel,
            'job' => serialize($job),
            'created_at' => time(),
        ])->execute();
    }

    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        if ($app instanceof \yii\console\Application) {
            $app->controllerMap[$this->queue->id] = [
                'class' => Command::class,
                'driver' => $this,
            ];
        }
    }

    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::class);
        $this->mutex = Instance::ensure($this->mutex, Mutex::class);
    }

    public function run($channel)
    {
        while ($message = $this->pop($channel)) {
            $job = unserialize($message['job']);
            $this->getQueue()->run($channel, $job);
            $this->release($message);
        }
    }

    protected function pop($channel)
    {
        $this->mutex->acquire(__CLASS__ . $channel);

        $message = (new Query())
            ->from($this->tableName)
            ->where(['channel' => $channel, 'started_at' => null])
            ->orderBy(['id' => SORT_ASC])
            ->limit(1)
            ->one($this->db);

        if (is_array($message)) {
            $message['started_at'] = time();
            $this->db->createCommand()->update(
                $this->tableName,
                ['started_at' => $message['started_at']],
                ['id' => $message['id']]
            )->execute();
        }

        $this->mutex->release(__CLASS__ . $channel);

        return $message;
    }

    protected function release($message)
    {
        if ($this->deleteReleased) {
            $this->db->createCommand()->delete(
                $this->tableName,
                ['id' => $message['id']]
            )->execute();
        } else {
            $this->db->createCommand()->update(
                $this->tableName,
                ['finished_at' => time()],
                ['id' => $message['id']]
            )->execute();
        }
    }
}