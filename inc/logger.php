<?php


/**
 * SMDP_Logger Class
 *
 * A simple JSON-based logger for WordPress plugins.
 * Logs are stored in a `/log/` directory inside your plugin.
 *
 * @package  SMDP_Extentions
 * @author   SMDP
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Get the logger instance (pass your plugin directory once)
 * $logger = SMDP_Logger::get_instance( SMDP_DIR );
 * 
 * ✅ Write a log entry
 * $logger->write( [
 *    'event'   => 'order_created',
 *    'orderId' => 123,
 *    'status'  => 'success'
 * ] );
 *
 * ✅ Read all log entries
 * $logs = $logger->read();
 * print_r( $logs );
 *
 * ✅ Clear logs
 * $logger->clear();
 * 
 */


if (! class_exists('SMDP_Logger')) {

    /**
     * Class SMDP_Logger
     *
     * Singleton class for logging data into JSON files.
     * Each log entry includes a timestamp and the data payload.
     */
    final class SMDP_Logger
    {

        /**
         * Holds the Singleton instance.
         *
         * @var SMDP_Logger|null
         */
        private static $instance = null;

        /**
         * Directory where logs will be saved.
         *
         * @var string
         */
        private $log_dir;

        /**
         * Private constructor (Singleton).
         *
         * @param string $plugin_dir Base plugin directory path.
         */
        private function __construct($plugin_dir)
        {
            $this->log_dir = trailingslashit($plugin_dir) . 'log/';

            if (! file_exists($this->log_dir)) {
                wp_mkdir_p($this->log_dir); // WP-safe mkdir
            }
        }

        /**
         * Get the Singleton instance of the logger.
         *
         * @param string $plugin_dir Plugin directory path (only required on first call).
         * @return SMDP_Logger
         */
        public static function get_instance($plugin_dir = '')
        {
            if (null === self::$instance) {
                if (empty($plugin_dir)) {
                    // Fallback: use this file’s directory if not provided
                    $plugin_dir = plugin_dir_path(__FILE__);
                }
                self::$instance = new self($plugin_dir);
            }
            return self::$instance;
        }

        /**
         * Write data to a log file.
         *
         * @param mixed  $data      Data to log (array, object, string, etc.).
         * @param string $file_name File name (default: log.json).
         * @return void
         */
        public function write($data, $file_name = 'log.json')
        {
            $file_path = $this->log_dir . $file_name;
            $logs      = [];

            // Load existing logs
            if (file_exists($file_path)) {
                $file_contents = file_get_contents($file_path);
                $decoded       = json_decode($file_contents, true);
                if (is_array($decoded)) {
                    $logs = $decoded;
                }
            }

            // Add new log entry with timestamp
            $logs[] = [
                'timestamp' => current_time('mysql'),
                'data'      => $data,
            ];

            $json_data = wp_json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($file_path, $json_data) === false) {
                error_log("Failed to write to log file at: $file_path");
            }
        }

        /**
         * Read logs from a file.
         *
         * @param string $file_name File name (default: log.json).
         * @return array Logs (each log contains timestamp + data) or empty array if none.
         */
        public function read($file_name = 'log.json')
        {
            $file_path = $this->log_dir . $file_name;

            if (file_exists($file_path)) {
                $contents = file_get_contents($file_path);
                $decoded  = json_decode($contents, true);
                return is_array($decoded) ? $decoded : [];
            }

            return [];
        }

        /**
         * Clear all logs in a file (resets to empty JSON array).
         *
         * @param string $file_name File name (default: log.json).
         * @return void
         */
        public function clear($file_name = 'log.json')
        {
            $file_path = $this->log_dir . $file_name;

            if (file_exists($file_path)) {
                file_put_contents($file_path, wp_json_encode([], JSON_PRETTY_PRINT));
            }
        }
    }
}
