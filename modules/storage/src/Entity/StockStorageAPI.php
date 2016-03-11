<?php

/**
 * @file
 * Contains \Drupal\commerce_stock_s\Entity\StockStorageAPI.
 */


namespace Drupal\commerce_stock_s\Entity;


use Drupal\commerce_stock\Entity\EntityStockCheckInterface;
use Drupal\commerce_stock\Entity\EntityStockUpdateInterface;



class StockStorageAPI implements EntityStockCheckInterface, EntityStockUpdateInterface {

  /**
   * Create a stock transaction.
   */
  public function createTransaction($product_id, $location_id, $zone, $quantity, $unit_cost) {
    // Create a record.
    // @todo - Deprecated replace with https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Database%21Connection.php/function/Connection%3A%3Ainsert/8
    $io = db_insert('cs_inventory_transaction');
    $io->fields( array('product_id', 'qty', 'location_id', 'location_zone', 'unit_cost') ) ;
    $io->values( array($product_id, $quantity, $location_id, $zone,  $unit_cost) );
    $io->execute();
  }

  /**
   * Update the stock level by adding up the transaction that got created since
   * the last time this was run.
   */
  public function updateProductInventoryLocationLevel($location_id, $product_id) {
    // Get the location level & last transaction.
    $db = \Drupal::database();
    $result = $db->select('cs_inventory_location_level', 'ill')
      ->fields('ill')
      //->condition('location_id', 1)
      ->condition('location_id', $location_id, '=')
      ->condition('product_id', $product_id)
      ->execute()
      ->fetch();
      if ($result) {
        $stock_level = $result->qty;
        $last_transaction = $result->last_transaction_id;
      }
      else {
        $stock_level = 0;
        $last_transaction = 0;
        // Create a record.
        // @todo - Deprecated replace with https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Database%21Connection.php/function/Connection%3A%3Ainsert/8
        $io = db_insert('cs_inventory_location_level');
        $io->fields( array('location_id', 'product_id', 'qty', 'last_transaction_id') ) ;
        $io->values( array($location_id, $product_id, 0, 0) );
        $io->execute();
      }

      // Get the last transaction id.
      // @todo - need to use a higher level method.
      $query = "select max(trid) as max_id from `{cs_inventory_transaction}`
      WHERE (`location_id` = '" . $location_id . "')
      AND (`product_id` =" . $product_id . ")
      GROUP BY location_id";
      $result = $db->query($query)->fetch();
      if (!$result) {
        // If no new transaction then nothing to do.
        return;
      }
      $max_transaction = $result->max_id;

      // Total all transactions between last and max transactions.
      // @todo - need to use a higher level method.
      $query = "SELECT location_id, sum(qty) as transactions_qty FROM `{cs_inventory_transaction}`
      WHERE (`location_id` = '" . $location_id . "')
      AND (`product_id` =" . $product_id . ")
      AND (`trid` > " . $last_transaction .")
      AND (`trid` <= " . $max_transaction .")
      GROUP BY location_id";
      $result = $db->query($query)->fetch();
      if ($result) {
        // Add the transactions qty to the existing location level.
        $stock_level += $result->transactions_qty;
        // @todo - use non Deprecated function.
        $io = db_update('cs_inventory_location_level');
        $io->fields(array('qty' => $stock_level, 'last_transaction_id' => $max_transaction ));
        $io->condition('location_id', $location_id, '=');
        $io->condition('product_id', $product_id, '=');
        $io->execute();
      }
  }

  /**
   * Gets the Stock level.
   *
   * @return int
   *   Stock Level.
   */
  public function getStockLevel($product_id, $locations) {
    $location_info = $this->getStockLocationLevel($product_id, $locations);
    // Add the quentities together and return.
    $qty = 0;
    foreach ($location_info as $location) {
      $qty += $location['qty']  + $location['transactions_qty'];
    }
    return $qty;
  }

  /**
   * Returns array containing stock level across all locations.
   */
  public function getStockLocationLevel($product_id, $locations) {
    // An array to hold stock data for the listed locations.
    $location_info = array();
    foreach ($locations as $location_id) {
      $location_info[$location_id] = array(
        'qty' => 0,
        'last_transaction' => 0,
        'transactions_qty' => 0,
      );
    }

    // Get the location level & last transaction.
    $db = \Drupal::database();
    $result = $db->select('cs_inventory_location_level', 'ill')
      ->fields('ill')
      //->condition('location_id', 1)
      ->condition('location_id', $locations, 'IN')
      ->condition('product_id', $product_id)
      ->execute()
      ->fetchAll();
      //->fetch();
      if ($result) {
        foreach ($result as $record) {
          // Location info for retriving transactions.
          $location_info[$record->location_id]['qty'] = $record->qty;
          $location_info[$record->location_id]['last_transaction'] = $record->last_transaction_id;

        }
      }

    // Cycle all locations to toal the transactions.
    foreach ($location_info as $location_id => $location) {
      // @todo - need to use a higher level method, the select below for details
      $query = "SELECT location_id, sum(qty) as transactions_qty FROM `{cs_inventory_transaction}`
      WHERE (`location_id` = '" . $location_id . "')
      AND (`product_id` =" . $product_id . ")
      AND (`trid` > " . $location['last_transaction'] .")
      GROUP BY location_id";
      $result = $db->query($query)->fetch();
      if ($result) {
        $location_info[$location_id]['transactions_qty'] = $result->transactions_qty;
      }
    }
    return $location_info;
  }


  /**
   * Check if product is in stock.
   *
   * @return bool
   *   TRUE if the product is in stock, FALSE otherwise.
   */
  public function getIsInStock($product_id, $locations) {
    return ($this->getStockLevel($product_id, $locations) > 0);
  }


  /**
   * Check if product is always in stock.
   *
   * @return bool
   *   TRUE if the product is in stock, FALSE otherwise.
   */
  public function getIsAlwaysInStock($product_id) {
    // @todo - not yet implamanted.
    return FALSE;
  }

  /**
   * Check if product is managed by stock.
   *
   * @return bool
   *   TRUE if the product is in stock, FALSE otherwise.
   */
  public function getIsStockManaged($product_id) {
    // @todo - not yet implamanted, so for now all products are managed.
    return TRUE;
  }

  /**
   * Get list of locations.
   *
   * @return array
   *   List of locations keyd by ID.
   */
  public function getLocationList($return_active_only = TRUE) {
    // Build he query.
    $db = \Drupal::database();
    $query = $db->select('cs_inventory_location', 'il')->fields('il');
    // If only active locations.
    if ($return_active_only) {
      // Add a where condition.
      $query->condition('status', 1);
    }
    // Run.
    $result = $query->execute()->fetchAll();
    $location_info = array();
    if ($result) {
      foreach ($result as $record) {
        // Location info for retriving transactions.
        $location_info[$record->locid] = array(
          'name' => $record->name,
          'status' => $record->status,
        );
      }
    }
    return $location_info;
  }

}
