<?php
/**
 * Plugin Name: WooCommerce Phone Authentication & Email Verification
 * Description: Adds phone number to registration form, requires email verification, and allows login with phone
 * Version: 1.0.5
 * Author: Nabil
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Main plugin class
 */
class WC_Phone_Auth_Email_Verification {
    /**
     * Instance variable
     *
     * @var WC_Phone_Auth_Email_Verification
     */
    private static $instance = null;

    /**
     * Get single instance
     *
     * @return WC_Phone_Auth_Email_Verification
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Run early to disable WooCommerce registration notices
        add_action('init', array($this, 'registration_notice_filter'), 1);
        
        // Early notice cleanup
        add_action('wp_loaded', array($this, 'early_notice_cleanup'), 5);
        
        // Registration hooks
        add_action('woocommerce_register_form', array($this, 'add_phone_field_registration'), 11);
        add_filter('woocommerce_registration_errors', array($this, 'validate_phone_field_registration'), 10, 3);
        
        // IMPORTANT: This line prevents the automatic login after registration
        add_filter('woocommerce_registration_auth_new_customer', '__return_false');
        
        // IMPORTANT: Completely disable default new account email
        add_filter('woocommerce_email_enabled_customer_new_account', '__return_false', 999);
        
        // Phone and customer hooks
        add_action('woocommerce_created_customer', array($this, 'save_phone_field_registration'));
        add_action('woocommerce_created_customer', array($this, 'send_verification_email'), 10, 3);
        
        // Scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_intl_tel_input'));
        
        // Checkout fields reordering
        add_filter('woocommerce_checkout_fields', array($this, 'reorder_checkout_fields'));
        
        // Login with phone
        add_filter('authenticate', array($this, 'login_with_phone_email'), 20, 3);
        add_action('woocommerce_login_form_start', array($this, 'modify_login_form'));
        add_filter('woocommerce_login_form_fields', array($this, 'modify_login_username_field'));
        
        // Account details form
        add_action('woocommerce_edit_account_form', array($this, 'add_phone_to_account_details'));
        add_filter('woocommerce_save_account_details_errors', array($this, 'validate_phone_in_account_details'), 10, 2);
        add_action('woocommerce_save_account_details', array($this, 'save_phone_in_account_details'));
        
        // Email verification
        add_action('init', array($this, 'register_verify_account_endpoint'));
        add_action('template_redirect', array($this, 'handle_verification_request'));
        add_filter('wp_authenticate_user', array($this, 'check_email_verification'), 10, 2);
        
        // Move phone field to proper position in registration form
        add_action('wp_footer', array($this, 'move_phone_field_script'));
        
        // Redirect after login
        add_filter('woocommerce_login_redirect', array($this, 'redirect_after_login'), 10, 2);
        add_filter('login_redirect', array($this, 'redirect_after_login'), 10, 3);
        
        // Prevent duplicate notices
        add_action('woocommerce_before_customer_login_form', array($this, 'prevent_duplicate_notices'));
        add_action('woocommerce_before_checkout_form', array($this, 'prevent_duplicate_notices'));
        add_action('woocommerce_before_shop_loop', array($this, 'prevent_duplicate_notices'));
        add_action('woocommerce_before_single_product', array($this, 'prevent_duplicate_notices'));
        
        // Fix user session issues
        add_action('wp', array($this, 'fix_user_sessions'));
    }
    
    /**
     * Modified registration notice filter
     * Completely prevent ALL WooCommerce registration notices
     */
    public function registration_notice_filter() {
        // Remove ALL default WooCommerce registration notices
        if (isset($_POST['register'])) {
            // Remove the standard WooCommerce hooks that add notices
            remove_all_actions('woocommerce_registration_redirect', 10);
            remove_all_actions('woocommerce_after_register_post', 10);
            
            // Disable default registration emails completely
            add_filter('woocommerce_email_enabled_customer_new_account', '__return_false', 999);
            
            // Hook into woocommerce_add_notice to prevent specific registration notices
            add_filter('woocommerce_add_notice', array($this, 'filter_woocommerce_notices'), 10, 3);
            
            // Clear any existing notices
            if (function_exists('wc_clear_notices')) {
                // Get all notices first
                $all_notices = WC()->session->get('wc_notices', array());
                
                // Filter success notices to remove account creation ones
                if (isset($all_notices['success'])) {
                    foreach ($all_notices['success'] as $key => $notice) {
                        // Remove any notice that contains these common account creation phrases
                        if (strpos($notice, 'account') !== false || 
                            strpos($notice, 'Account') !== false || 
                            strpos($notice, 'created') !== false || 
                            strpos($notice, 'registered') !== false ||
                            strpos($notice, 'login details') !== false) {
                            unset($all_notices['success'][$key]);
                        }
                    }
                    // Save the filtered notices back
                    WC()->session->set('wc_notices', $all_notices);
                }
            }
        }
    }
    
    /**
     * Filter WooCommerce notices to prevent registration-related ones
     */
    public function filter_woocommerce_notices($message, $notice_type, $data) {
        // Only filter success notices
        if ($notice_type === 'success') {
            // Check if this is a registration or account creation notice
            $blocked_phrases = array(
                'your account', 'account has been', 'account was', 
                'login details', 'created successfully', 'registered',
                'verification', 'verify your'
            );
            
            foreach ($blocked_phrases as $phrase) {
                if (stripos($message, $phrase) !== false) {
                    // Return empty string to prevent the notice
                    return '';
                }
            }
        }
        
        // Return original message for other notices
        return $message;
    }
    
    /**
     * Early notice cleanup
     * Runs at wp_loaded to clean any lingering notices
     */
    public function early_notice_cleanup() {
        if (isset($_POST['register']) || isset($_GET['verify_email'])) {
            // Get session notices
            if (function_exists('WC') && WC()->session) {
                $notices = WC()->session->get('wc_notices', array());
                
                // Filter success notices to keep only our verification notice
                if (isset($notices['success']) && is_array($notices['success'])) {
                    foreach ($notices['success'] as $key => $notice) {
                        // Keep only our specific verification notice
                        if (strpos($notice, 'Please check your email to verify') === false) {
                            unset($notices['success'][$key]);
                        }
                    }
                    
                    // If there are multiple success notices remaining, keep only the last one
                    if (count($notices['success']) > 1) {
                        $notices['success'] = array(end($notices['success']));
                    }
                    
                    // Reset array keys
                    $notices['success'] = array_values($notices['success']);
                    
                    // Save back to session
                    WC()->session->set('wc_notices', $notices);
                }
            }
        }
    }
    
    /**
     * Fix user sessions
     * Fix user session issues to prevent unexpected logouts
     */
    public function fix_user_sessions() {
        // Only run for logged-in users on account pages
        if (is_user_logged_in() && is_account_page()) {
            $user_id = get_current_user_id();
            
            // Make sure the user is verified
            $verified = get_user_meta($user_id, 'email_verified', true);
            
            if ($verified) {
                // Make sure the user has the proper role
                $user = new WP_User($user_id);
                if (!in_array('customer', $user->roles) && !in_array('administrator', $user->roles)) {
                    $user->set_role('customer');
                }
                
                // Refresh the session
                if (!isset($_COOKIE[LOGGED_IN_COOKIE])) {
                    wp_set_auth_cookie($user_id, true);
                }
            }
        }
    }
    
    /**
     * Redirect after login
     * Redirect users to home page after login
     */
    public function redirect_after_login($redirect, $user) {
        // Only redirect non-admin users
        if ($user instanceof WP_User && !in_array('administrator', $user->roles)) {
            return home_url();
        }
        return $redirect;
    }
    
    /**
     * Prevent duplicate notices
     * Prevent duplicate notices during registration and other WooCommerce pages
     */
    public function prevent_duplicate_notices() {
        if (function_exists('wc_notice_count') && wc_notice_count('success') > 1) {
            // Get all notices
            $all_notices = WC()->session->get('wc_notices', array());
            
            // If we have multiple success notices, keep only the last one
            if (isset($all_notices['success']) && count($all_notices['success']) > 1) {
                $all_notices['success'] = array(end($all_notices['success']));
                WC()->session->set('wc_notices', $all_notices);
            }
        }
    }
    
    /**
     * Send the WooCommerce new account email after verification
     * 
     * @param int $user_id
     */
    public function send_new_account_email($user_id) {
        // Get the user data
        $user = get_userdata($user_id);
        
        // Get WooCommerce mailer
        $mailer = WC()->mailer();
        
        // Email subject and heading
        $email_subject = sprintf(__('Welcome to %s', 'woocommerce'), get_bloginfo('name'));
        
        // Email content
        $email_content = '<p>' . sprintf(__('Hi %s,', 'woocommerce'), $user->display_name) . '</p>';
        $email_content .= '<p>' . sprintf(__('Thanks for creating an account on %s. Your account has been verified and is now active.', 'woocommerce'), get_bloginfo('name')) . '</p>';
        $email_content .= '<p>' . sprintf(__('Your username is: %s', 'woocommerce'), $user->user_login) . '</p>';
        $email_content .= '<p>' . __('You can access your account area to view orders, change your password, and more at:', 'woocommerce') . ' <a href="' . wc_get_page_permalink('myaccount') . '">' . wc_get_page_permalink('myaccount') . '</a></p>';
        $email_content .= '<p>' . __('We look forward to seeing you soon.', 'woocommerce') . '</p>';
        
        // Create a new email
        $email_headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Send the email
        $mailer->send($user->user_email, $email_subject, $email_content, $email_headers);
    }
    
    /**
     * Move phone field script
     * Adds JavaScript to move the phone field to the correct position
     */
    public function move_phone_field_script() {
        // For registration page
        if (is_account_page() && !is_user_logged_in()) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Move the phone field after the email field
                    var emailField = $('.woocommerce-form-register').find('#reg_email').closest('p');
                    var phoneField = $('.woocommerce-form-register').find('#billing_phone').closest('p');
                    
                    if (emailField.length && phoneField.length) {
                        phoneField.insertAfter(emailField);
                    }
                });
            </script>
            <?php
        }
        // For My Account > Account details page 
        if (is_account_page() && is_user_logged_in()) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Move the phone field after the email field in account details
                    var emailField = $('.woocommerce-EditAccountForm').find('#account_email').closest('p');
                    var phoneField = $('.woocommerce-EditAccountForm').find('#billing_phone').closest('p');
                    
                    if (emailField.length && phoneField.length) {
                        phoneField.insertAfter(emailField);
                    }
                });
            </script>
            <?php
        }
    }

    /**
     * Add phone field to registration form
     */
    public function add_phone_field_registration() {
        woocommerce_form_field('billing_phone', array(
            'type'        => 'tel',
            'required'    => true,
            'label'       => __('Phone', 'woocommerce'),
            'placeholder' => __('Enter phone number with country code', 'woocommerce'),
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'priority'    => 15, // Between email (10) and password (20)
        ));
    }

    /**
     * Validate phone field during registration
     */
    public function validate_phone_field_registration($errors, $username, $email) {
        if (isset($_POST['billing_phone']) && empty($_POST['billing_phone'])) {
            $errors->add('billing_phone_error', __('Please enter your phone number.', 'woocommerce'));
        } elseif (isset($_POST['billing_phone'])) {
            // Simple validation that it starts with + and contains only numbers, spaces, and dashes after that
            if (!preg_match('/^\+[0-9\s\-]+$/', $_POST['billing_phone'])) {
                $errors->add('billing_phone_error', __('Please enter a valid phone number with country code (e.g. +1 for US).', 'woocommerce'));
            }
        }
        
        // Check if phone is already registered
        if (!empty($_POST['billing_phone']) && !$errors->get_error_code()) {
            $phone = sanitize_text_field($_POST['billing_phone']);
            $user_query = new WP_User_Query(array(
                'meta_key' => 'billing_phone',
                'meta_value' => $phone
            ));
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                $errors->add('phone_exists', __('An account is already registered with this phone number. Please login.', 'woocommerce'));
            }
        }
        
        return $errors;
    }

    /**
     * Save phone field value during registration
     */
    public function save_phone_field_registration($customer_id) {
        if (isset($_POST['billing_phone'])) {
            update_user_meta($customer_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
        }
    }

    /**
     * Enqueue international telephone input scripts and styles
     */
    public function enqueue_intl_tel_input() {
        if (is_account_page() || is_checkout() || is_page('my-account')) {
            wp_enqueue_style('intl-tel-input-css', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.css');
            wp_enqueue_script('intl-tel-input-js', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/intlTelInput.min.js', array('jquery'), null, true);
            wp_enqueue_script('intl-tel-input-utils', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js', array('intl-tel-input-js'), null, true);
            
            // Add custom JS to initialize the plugin
            wp_add_inline_script('intl-tel-input-js', '
                jQuery(document).ready(function($) {
                    var input = document.querySelector("[name=\'billing_phone\']");
                    if(input) {
                        var iti = window.intlTelInput(input, {
                            utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js",
                            separateDialCode: true,
                            initialCountry: "auto",
                            geoIpLookup: function(callback) {
                                $.get("https://ipinfo.io", function() {}, "jsonp").always(function(resp) {
                                    var countryCode = (resp && resp.country) ? resp.country : "us";
                                    callback(countryCode);
                                });
                            }
                        });
                        
                        // Fix styling issues
                        $(".iti").css("width", "100%");
                        $("[name=\'billing_phone\']").css({
                            "padding-left": "90px",
                            "width": "100%"
                        });
                        
                        // Store the full number when the form is submitted
                        $("form.register, form.woocommerce-form-login, form.edit-account").submit(function() {
                            if(iti && input) {
                                input.value = iti.getNumber();
                            }
                        });
                    }
                });
            ');
            
            // Add custom CSS to fix styling issues
            wp_add_inline_style('intl-tel-input-css', '
                .iti {
                    width: 100%;
                    display: block;
                }
                .iti__flag-container {
                    z-index: 99;
                }
                .woocommerce form .form-row .input-text, 
                .woocommerce-page form .form-row .input-text {
                    box-sizing: border-box;
                    width: 100%;
                }
            ');
        }
    }

    /**
     * Reorder checkout fields to put phone after email
     */
    public function reorder_checkout_fields($fields) {
        if (isset($fields['billing']['billing_phone']) && isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_phone']['priority'] = 115; // Just after email (110)
        }
        return $fields;
    }

    /**
     * Allow login with phone number
     */
    public function login_with_phone_email($user, $username, $password) {
        // Check if user is already authenticated
        if ($user instanceof WP_User) {
            return $user;
        }
        
        // Check if username contains @ (email)
        if (is_email($username)) {
            // This is an email, let WordPress handle it
            return null;
        }
        
        // Try to normalize phone number
        $phone = sanitize_text_field($username);
        
        // If it doesn't have a +, assume it might be a phone number
        if (strpos($phone, '+') !== 0) {
            // User might be trying to log in with a phone number without + symbol
            $phone = '+' . preg_replace('/[^0-9]/', '', $phone);
        }
        
        // Look up user by phone number
        $user_query = new WP_User_Query(array(
            'meta_key' => 'billing_phone',
            'meta_value' => $phone
        ));
        $users = $user_query->get_results();
        
        // If found, authenticate the user with the provided password
        if (!empty($users)) {
            $user_obj = $users[0];
            $user_id = $user_obj->ID;
            
            // Check if password matches
            if (wp_check_password($password, $user_obj->user_pass, $user_id)) {
                return $user_obj;
            }
        }
        
        return null;
    }

    /**
     * Add phone to Account details form
     */
    public function add_phone_to_account_details() {
        $user_id = get_current_user_id();
        $user_phone = get_user_meta($user_id, 'billing_phone', true);
        
        woocommerce_form_field('billing_phone', array(
            'type'        => 'tel',
            'required'    => true,
            'label'       => __('Phone', 'woocommerce'),
            'placeholder' => __('Enter phone number with country code', 'woocommerce'),
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'default'     => $user_phone,
        ));
    }

    /**
     * Validate phone field in Account details form
     */
    public function validate_phone_in_account_details($errors, $user) {
        if (empty($_POST['billing_phone'])) {
            $errors->add('billing_phone_error', __('Please enter your phone number.', 'woocommerce'));
        } elseif (!preg_match('/^\+[0-9\s\-]+$/', $_POST['billing_phone'])) {
            $errors->add('billing_phone_error', __('Please enter a valid phone number with country code (e.g. +1 for US).', 'woocommerce'));
        }
        
        // Check if phone is already registered to another user
        if (!empty($_POST['billing_phone']) && !$errors->get_error_code()) {
            $phone = sanitize_text_field($_POST['billing_phone']);
            $current_user_id = get_current_user_id();
            
            $user_query = new WP_User_Query(array(
                'meta_key' => 'billing_phone',
                'meta_value' => $phone,
                'exclude' => array($current_user_id)
            ));
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                $errors->add('phone_exists', __('This phone number is already registered to another account.', 'woocommerce'));
            }
        }
        
        return $errors;
    }

    /**
     * Save the phone field from Account details form
     */
    public function save_phone_in_account_details($user_id) {
        if (isset($_POST['billing_phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
        }
    }

    /**
     * Modify login form to indicate phone login is possible
     */
    public function modify_login_form() {
        ?>
        <p class="login-note"><?php _e('You can login with your email address or phone number.', 'woocommerce'); ?></p>
        <?php
    }

    /**
     * Add hint to username field
     */
    public function modify_login_username_field($fields) {
        $fields['username']['placeholder'] = __('Email or phone number', 'woocommerce');
        $fields['username']['label'] = __('Email or phone number', 'woocommerce');
        return $fields;
    }

    /**
     * Send verification email when a new customer is created
     * Modified to completely clear all notices and add just one
     */
    public function send_verification_email($customer_id, $new_customer_data, $password_generated) {
        // Generate unique verification code
        $verification_code = wp_generate_password(20, false);
        
        // Store verification code in user meta
        update_user_meta($customer_id, 'email_verification_code', $verification_code);
        update_user_meta($customer_id, 'email_verified', false);
        
        // Mark user as inactive until verified
        $user = new WP_User($customer_id);
        $user->set_role(''); // Remove default role
        
        // Create verification URL
        $verification_url = add_query_arg(array(
            'verify_email' => 'true',
            'user_id' => $customer_id,
            'code' => $verification_code
        ), home_url());
        
        // Get the WooCommerce mailer
        $mailer = WC()->mailer();
        
        // Email subject and heading
        $email_subject = 'Verify your account on ' . get_bloginfo('name');
        
        // Email content
        $email_content = '<p>Thank you for registering on our website. Please click the link below to verify your email address:</p>';
        $email_content .= '<p><a href="' . esc_url($verification_url) . '">Verify your email address</a></p>';
        $email_content .= '<p>If you did not create an account, you can safely ignore this email.</p>';
        
        // Create a new email
        $email_headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Send the email
        $mailer->send($new_customer_data['user_email'], $email_subject, $email_content, $email_headers);
        
        // *** Critical modification: Force-remove all notices ***
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        
        // Remove ALL notices from session directly
        if (WC()->session) {
            WC()->session->set('wc_notices', array());
        }
        
        // Add only our custom notice
        wc_add_notice(__('Your account has been created. Please check your email to verify your account before logging in.', 'woocommerce'), 'success');
        
        // Force this notice to display immediately
        add_filter('woocommerce_notice_types', function($notice_types) {
            return array('success');
        }, 999);
    }

    /**
     * Register verification endpoint
     */
    public function register_verify_account_endpoint() {
        add_rewrite_endpoint('verify-account', EP_ROOT);
    }

    /**
     * Handle the verification request
     */
    public function handle_verification_request() {
        if (isset($_GET['verify_email']) && $_GET['verify_email'] === 'true' && isset($_GET['user_id']) && isset($_GET['code'])) {
            $user_id = absint($_GET['user_id']);
            $code = sanitize_text_field($_GET['code']);
            
            // Get stored verification code
            $stored_code = get_user_meta($user_id, 'email_verification_code', true);
            
            if ($code === $stored_code) {
                // Mark user as verified
                update_user_meta($user_id, 'email_verified', true);
                
                // Assign the proper role
                $user = new WP_User($user_id);
                $user->set_role('customer');
                
                // Delete verification code
                delete_user_meta($user_id, 'email_verification_code');
                
                // Now send the welcome email with account details
                $this->send_new_account_email($user_id);
                
                // Clear all existing notices first
                if (function_exists('wc_clear_notices')) {
                    wc_clear_notices();
                }
                
                // Add success message
                wc_add_notice(__('Your email has been verified successfully. You can now log in to your account.', 'woocommerce'), 'success');
                
                // Redirect to login page
                wp_redirect(wc_get_page_permalink('myaccount'));
                exit;
            } else {
                wc_add_notice(__('Invalid verification code. Please contact support for assistance.', 'woocommerce'), 'error');
                wp_redirect(wc_get_page_permalink('myaccount'));
                exit;
            }
        }
    }

    /**
     * Block login if email not verified
     */
    public function check_email_verification($user, $password) {
        // Skip for admin users
        if ($user instanceof WP_User && in_array('administrator', $user->roles)) {
            return $user;
        }
        
        if ($user instanceof WP_User) {
            // Check if user email is verified
            $verified = get_user_meta($user->ID, 'email_verified', true);
            
            if (!$verified) {
                // Stop login process with an error
                return new WP_Error('email_not_verified', __('Your email address has not been verified. Please check your email for the verification link.', 'woocommerce'));
            }
        }
        
        return $user;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    // Make sure WooCommerce is active
    if (class_exists('WooCommerce')) {
        WC_Phone_Auth_Email_Verification::get_instance();
    }
});