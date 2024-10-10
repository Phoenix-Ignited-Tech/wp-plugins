<?php

/**
 * Plugin Name: PXI Delete Product Images by Merchant
 * Description: Deletes all product images by merchant ID, starts 20 seconds after plugin activation, deletes products, and sends email notifications after every 50 batches.
 * Version: 1.0
 * Author: Phoenix Ignited Tech
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

ini_set('max_execution_time', 7000);  // Set to 3000 seconds or higher if needed
ini_set('memory_limit', '2048M');      // Set to 512 MB or higher if needed

// Run the image deletion process 20 seconds after plugin activation
register_activation_hook(__FILE__, 'pxi_schedule_image_deletion');

function pxi_schedule_image_deletion() {
    // Schedule the deletion process to run 20 seconds after activation
    if (!wp_next_scheduled('pxi_run_image_deletion')) {
        wp_schedule_single_event(time() + 20, 'pxi_run_image_deletion');
    }
}

// Hook for the scheduled event
add_action('pxi_run_image_deletion', 'pxi_delete_merchant_images_batch');

// Main function to delete merchant images in batches and delete products
function pxi_delete_merchant_images_batch() {
    global $wpdb;

    $batch_size = 10;  // Number of products/images to delete in each batch
    $delay_time = 0;   // Delay between each batch in seconds
    $batch_count = 0;  // Counter for batches processed
    $batches_per_email = 50;  // Send an email after every 50 batches

    // Define the log file path
    $log_file = WP_CONTENT_DIR . '/pxi-image-deletion-log.txt';

    // Log the start of the process
    pxi_log_message($log_file, "Image deletion process started.");

    while (true) {
        $batch_count++;

        // Get a batch of 10 product IDs related to a specific user ID -be sure to declare the user id here!!
        $product_ids = $wpdb->get_col("
            SELECT p.ID 
            FROM {$wpdb->prefix}posts p
            WHERE p.post_author = '##'  
            AND p.post_type = 'product'
            LIMIT $batch_size
        ");

        // If no more products are left, stop the process
        if (empty($product_ids)) {
            pxi_log_message($log_file, "No more products found. Process complete.");
            break;
        }

        // Process each product to find related images and delete the product
        foreach ($product_ids as $product_id) {
            // Find only image attachments for the product
            $attachment_ids = $wpdb->get_col("
                SELECT p.ID
                FROM {$wpdb->prefix}posts p
                WHERE p.post_type = 'attachment'
                AND p.post_mime_type LIKE 'image/%'
                AND p.post_parent = $product_id
            ");

            // Delete each image attachment (this will delete all sizes and remove from media library)
            foreach ($attachment_ids as $attachment_id) {
                if (wp_delete_attachment($attachment_id, true)) {
                    pxi_log_message($log_file, "Deleted image attachment ID: $attachment_id");
                } else {
                    pxi_log_message($log_file, "Failed to delete image attachment ID: $attachment_id");
                }
            }

            // Now delete the product itself
            if (wp_delete_post($product_id, true)) {  // True: force delete
                pxi_log_message($log_file, "Deleted product ID: $product_id");
            } else {
                pxi_log_message($log_file, "Failed to delete product ID: $product_id");
            }
        }

        // After every 50 batches, send an email notification
        if ($batch_count % $batches_per_email == 0) {
            $email_to = 'me@example.com';  // Replace with your email
            $subject = "PXI Image Deletion and Product Deletion Progress";
            $message = "Processed $batch_count batches so far.";
            wp_mail($email_to, $subject, $message);
        }

        // Add a delay of 5 seconds between batches to allow the server to recover
        sleep($delay_time);
    }

    // Log when process is complete
    pxi_log_message($log_file, "Image and product deletion process completed.");
}

// Function to log messages
function pxi_log_message($file, $message) {
    // Prepend the current date and time to the message
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] $message\n";

    // Append the log entry to the file
    file_put_contents($file, $log_entry, FILE_APPEND);
}
