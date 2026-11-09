<?php

class Task_ForceAudit extends Task_Audit {

    const LOG_TAG = 'Task_ForceAudit';

    // ForceAudit never refreshed the gallery type, keep it that way
    protected $updateType = false;

    public function run($options = array()) {
        $this->client = new ExClient();
        $galleries = array();

        if (isset($options[0])) {
            $mode = strtolower($options[0]);
        } else {
            exit;
        }

        if ($mode == "all") {
            $galleries = R::findAll('gallery',
                'archived = 1 and deleted = 0 and source = 0');
        }

        if ($mode == "id") {
            if (isset($options[1])) {
                $id = (int)$options[1];
                $galleries = R::findAll('gallery',
                    'archived = 1 and deleted = 0 and source = 0 and id = ?',
                    [$id]);
            } else {
                exit;
            }
        }

        if ($mode == "before") {
            if (isset($options[1])) {
                $date = strtotime($options[1]);
                $date = date("Y-m-d H:i:s", $date);
                $galleries = R::findAll('gallery',
                    'archived = 1 and deleted = 0 and source = 0 ' .
                    'and added <= ?', [$date]);
            } else {
                exit;
            }
        }

        if ($mode == "after") {
            if (isset($options[1])) {
                $date = strtotime($options[1]);
                $date = date("Y-m-d H:i:s", $date);
                $galleries = R::findAll('gallery',
                    'archived = 1 and deleted = 0 and source = 0 ' .
                    'and added >= ?', [$date]);
            } else {
                exit;
            }
        }

        if ($mode == "range" || $mode == "between") {
            if (isset($options[1]) && isset($options[2])) {
                $startDate = strtotime($options[1]);
                $startDate = date("Y-m-d H:i:s", $startDate);

                $endDate = strtotime($options[2]);
                $endDate = date("Y-m-d H:i:s", $endDate);

                $galleries = R::findAll('gallery',
                    'archived = 1 and deleted = 0 and source = 0 ' .
                    'and added between :start and :end',
                    array(":start" => $startDate, ":end" => $endDate));
            } else {
                exit;
            }
        }

        if (count($galleries) == 0) {
            exit;
        }

        foreach($galleries as $gallery) {
            $this->audit($gallery);
        }

        Search::requestReindex(); // changed galleries only, in the background (docker/reindexer.sh)
    }

    // audit() and tagsDiff() are inherited from Task_Audit
}


?>
