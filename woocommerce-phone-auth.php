<?php
/**
 * Plugin Name: WooCommerce Phone Authentication
 * Description: Adds phone number to registration form and allows login with phone number
 * Version: 1.0
 * Author: Nabil
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Add phone field to registration form
 */
function add_phone_field_registration() {
    ?>
    <p class="form-row form-row-wide">
        <label for="reg_billing_phone"><?php _e('Phone', 'woocommerce'); ?> <span class="required">*</span></label>
        <input type="tel" class="input-text" name="billing_phone" id="reg_billing_phone" value="<?php if (!empty($_POST['billing_phone'])) echo esc_attr($_POST['billing_phone']); ?>" />
        <span class="description"><?php _e('Enter your phone number with country code (e.g. +1 for US)', 'woocommerce'); ?></span>
    </p>
    <?php
}
add_action('woocommerce_register_form', 'add_phone_field_registration');

/**
 * Validate phone field during registration
 */
function validate_phone_field_registration($errors, $username, $email) {
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
add_filter('woocommerce_registration_errors', 'validate_phone_field_registration', 10, 3);

/**
 * Save phone field value during registration
 */
function save_phone_field_registration($customer_id) {
    if (isset($_POST['billing_phone'])) {
        update_user_meta($customer_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
    }
}
add_action('woocommerce_created_customer', 'save_phone_field_registration');

/**
 * Enqueue international telephone input scripts and styles
 */
function enqueue_intl_tel_input() {
    if (is_account_page() || is_checkout()) {
        wp_enqueue_style('intl-tel-input-css', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.css');
        wp_enqueue_script('intl-tel-input-js', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/intlTelInput.min.js', array('jquery'), null, true);
        
        // Add custom JS to initialize the plugin
        wp_add_inline_script('intl-tel-input-js', '
            jQuery(document).ready(function($) {
                var input = document.querySelector("#reg_billing_phone");
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
                    
                    // Store the full number when the form is submitted
                    $("form.register").submit(function() {
                        if(iti) {
                            $("#reg_billing_phone").val(iti.getNumber());
                        }
                    });
                }
            });
        ');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_intl_tel_input');

/**
 * Allow login with phone number
 */
function login_with_phone_email($user, $username, $password) {
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
add_filter('authenticate', 'login_with_phone_email', 20, 3);

/**
 * Add phone to Account details form
 */
function add_phone_to_account_details() {
    $user_id = get_current_user_id();
    $user_phone = get_user_meta($user_id, 'billing_phone', true);
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="billing_phone"><?php _e('Phone', 'woocommerce'); ?> <span class="required">*</span></label>
        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="billing_phone" id="billing_phone" value="<?php echo esc_attr($user_phone); ?>" />
    </p>
    <?php
}
add_action('woocommerce_edit_account_form', 'add_phone_to_account_details');

/**
 * Validate phone field in Account details form
 */
function validate_phone_in_account_details($errors, $user) {
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
add_filter('woocommerce_save_account_details_errors', 'validate_phone_in_account_details', 10, 2);

/**
 * Save the phone field from Account details form
 */
function save_phone_in_account_details($user_id) {
    if (isset($_POST['billing_phone'])) {
        update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['billing_phone']));
    }
}
add_action('woocommerce_save_account_details', 'save_phone_in_account_details');

/**
 * Modify login form to indicate phone login is possible
 */
function modify_login_form() {
    ?>
    <p class="login-note"><?php _e('You can login with your email address or phone number.', 'woocommerce'); ?></p>
    <?php
}
add_action('woocommerce_login_form_start', 'modify_login_form');

/**
 * Add hint to username field
 */
function modify_login_username_field($fields) {
    $fields['username']['placeholder'] = __('Email or phone number', 'woocommerce');
    $fields['username']['label'] = __('Email or phone number', 'woocommerce');
    return $fields;
}
add_filter('woocommerce_login_form_fields', 'modify_login_username_field');
