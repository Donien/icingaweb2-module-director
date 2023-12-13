<?php

namespace Icinga\Module\Director\Clicommands;

use Icinga\Date\DateFormatter;
use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\Core\Json;
use Icinga\Module\Director\DirectorObject\Automation\Basket;
use Icinga\Module\Director\DirectorObject\Automation\BasketSnapshot;
use Icinga\Module\Director\DirectorObject\ObjectPurgeHelper;

/**
 * Export Director Config Objects
 */
class BasketCommand extends Command
{
    /**
     * List configured Baskets
     *
     * USAGE
     *
     * icingacli director basket list
     *
     * OPTIONS
     */
    public function listAction()
    {
        $db = $this->db()->getDbAdapter();
        $query = $db->select()
            ->from('director_basket', 'basket_name')
            ->order('basket_name');
        foreach ($db->fetchCol($query) as $name) {
            echo "$name\n";
        }
    }

    /**
     * JSON-dump for objects related to the given Basket
     *
     * USAGE
     *
     * icingacli director basket dump --name <basket>
     *
     * OPTIONS
     */
    public function dumpAction()
    {
        $basket = $this->requireBasket();
        $snapshot = BasketSnapshot::createForBasket($basket, $this->db());
        echo $snapshot->getJsonDump() . "\n";
    }

    /**
     * Take a snapshot for the given Basket
     *
     * USAGE
     *
     * icingacli director basket snapshot --name <basket>
     *
     * OPTIONS
     */
    public function snapshotAction()
    {
        $basket = $this->requireBasket();
        $snapshot = BasketSnapshot::createForBasket($basket, $this->db());
        $snapshot->store();
        $hexSum = bin2hex($snapshot->get('content_checksum'));
        printf(
            "Snapshot '%s' taken for Basket '%s' at %s\n",
            substr($hexSum, 0, 7),
            $basket->get('basket_name'),
            DateFormatter::formatDateTime($snapshot->get('ts_create') / 1000)
        );
    }

    /**
     * Restore a Basket from JSON dump provided on STDIN
     *
     * USAGE
     *
     * icingacli director basket restore < basket-dump.json
     *
     * OPTIONS
     *   --purge <ObjectType>[,<ObjectType] Purge objects of the
     *     Given types. WARNING: this removes ALL objects that are
     *     not shipped with the given basket
     *   --force Purge refuses to purge Objects in case there are
     *     no Objects of a given ObjectType in the provided basket
     *     unless forced to do so
     */
    public function restoreAction()
    {
        if ($purge = $this->params->get('purge')) {
            $purge = explode(',', $purge);
            ObjectPurgeHelper::assertObjectTypesAreEligibleForPurge($purge);
        }
        $json = file_get_contents('php://stdin');
        BasketSnapshot::restoreJson($json, $this->db());
        if ($purge) {
            $this->purgeObjectTypes(Json::decode($json), $purge, $this->params->get('force'));
        }
        echo "Objects from Basket Snapshot have been restored\n";
    }

    /**
     * Upload a Basket snapshot from JSON dump provided on STDIN
     *
     * USAGE
     *
     * icingacli director basket upload --name <basket> < basket-dump.json
     *
     * OPTIONS
     */
    public function uploadAction()
    {
        $basketName = $this->params->getRequired('name');
        $json = file_get_contents('php://stdin');
        /**
         * Removing trailing whitespaces / EOL to keep consistent SHA1 checksums.
         * When downloading a Basket in the UI, it has no EOL.
         * Actions like writing its content to another file manually adds an EOL
         * and so has the SHA1 checksum change.
         * Thus it currently needs to be stripped here.
         *
         * Also, when creating a new Basket from JSON ('BasketSnapshot::restoreJson')
         * the UUID is currently not taken into account. The basket is created with
         * the correct name but will use a newly generated UUID.
         * Thus it's currently not feasible to compare checksums to determine whether
         * to upload the provided JSON as a new snapshot.
         * -> Falling back to 'upload regardless'.
         */
        $json = rtrim($json);
        /**
         * CHECKSUM RELATED
         * This code block can be used once Basket UUIDs are used to
         * restore Baskets from JSON
         *
         * Change
         *  '$needsSnapshot = true;'
         * to
         *  '$needsSnapshot = false;'
         */
        $needsSnapshot = true;
        /**
         * CHECKSUM RELATED
         * This code block can be used once Basket UUIDs are used to
         * restore Baskets from JSON
         *
         * $checksum = sha1($json, false);
         */
        $db = $this->db()->getDbAdapter();
        $query = $db->select()
            ->from('director_basket', 'basket_name')
            ->where('basket_name = ?', $this->params->getRequired('name'))
            ->order('basket_name');
        $rows = $db->fetchCol($query);
        // Create Basket if needed
        if (empty($rows)) {
            $needsSnapshot = true;
            $jsonObj = Json::decode($json);
            $existingBasket = $jsonObj->Basket->$basketName->basket_name ?? false;
            if ($existingBasket) {
                $newBasket = Json::encode(array(
                    'Basket' => array(
                        $basketName => $jsonObj->Basket->$basketName
                    )
                ));
            } else {
                $newBasket = Json::encode(array(
                    "Basket" => array(
                        $basketName => array(
                            "basket_name" => $basketName,
                            "owner_type" => "user",
                            "owner_value" => "basket",
                            "objects" => array(
                                "Command" => true,
                                "ExternalCommand" => true,
                                "CommandTemplate" => true,
                                "HostGroup" => true,
                                "IcingaTemplateChoiceHost" => true,
                                "HostTemplate" => true,
                                "ServiceGroup" => true,
                                "IcingaTemplateChoiceService" => true,
                                "ServiceTemplate" => true,
                                "ServiceSet" => true,
                                "UserGroup" => true,
                                "UserTemplate" => true,
                                "User" => true,
                                "NotificationTemplate" => true,
                                "Notification" => true,
                                "TimePeriod" => true,
                                "Dependency" => true,
                                "DataList" => true,
                                "ImportSource" => true,
                                "SyncRule" => true,
                                "DirectorJob" => true,
                                "Basket" => true
                            )
                        )
                    )
                ));
            }
            BasketSnapshot::restoreJson($newBasket, $this->db());
            echo "Created Basket '" . $basketName . "'.\n";
        }
        /**
         * CHECKSUM RELATED
         * This code block can be used once Basket UUIDs are used to
         * restore Baskets from JSON
         *
         * else {
         *     // Abort if checksum is already present
         *     $query = $db->select()
         *         ->from('director_basket_snapshot', 'content_checksum')
         *         ->where('HEX(content_checksum) = ?', $checksum);
         *     $rows = $db->fetchCol($query);
         *     if (! empty($rows)) {
         *         $needsSnapshot = false;
         *         echo "Basket snapshot with checksum '" . $checksum . "' already exists.\n";
         *     } else {
         *         $needsSnapshot = true;
         *     }
         * }
         */
        if ($needsSnapshot) {
            $basket = $this->requireBasket();
            // Check validity of JSON keys
            foreach (Json::decode($json) as $type => $content) {
                if ($type !== 'Datafield') {
                    $basket->addObjects($type, array_keys((array) $content));
                }
            }
            BasketSnapshot::forBasketFromJson(
                $basket,
                $json
            )->store($this->db);
            /**
             * CHECKSUM RELATED
             * This code block can be used once Basket UUIDs are used to
             * restore Baskets from JSON
             *
             * echo "Basket snapshot with checksum '" . $checksum . "' has been uploaded\n";
             */
            echo "Basket snapshot has been uploaded\n";
        }
    }

    protected function purgeObjectTypes($objects, array $types, $force = false)
    {
        $helper = new ObjectPurgeHelper($this->db());
        if ($force) {
            $helper->force();
        }
        foreach ($types as $type) {
            list($className, $typeFilter) = BasketSnapshot::getClassAndObjectTypeForType($type);
            $helper->purge(
                isset($objects->$type) ? (array) $objects->$type : [],
                $className,
                $typeFilter
            );
        }
    }

    /**
     */
    protected function requireBasket()
    {
        return Basket::load($this->params->getRequired('name'), $this->db());
    }
}
