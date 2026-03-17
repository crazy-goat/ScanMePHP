/*
  +----------------------------------------------------------------------+
  | NativeEncoder class implementation                                   |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/info.h"
#include "php_scanme_qr.h"
#include "native_encoder.h"
#include "scanme_qr.h"

/* Object structure for NativeEncoder */
typedef struct {
    zend_object std;
} scanme_qr_native_encoder_object;

/* Class entry pointer */
zend_class_entry *scanme_qr_native_encoder_ce;

/* Forward declarations */
static zend_object *scanme_qr_native_encoder_create(zend_class_entry *class_type);
static void scanme_qr_native_encoder_free(zend_object *object);
static PHP_METHOD(NativeEncoderExt, encodeRaw);

/* Arginfo for NativeEncoderExt::encodeRaw() */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_NativeEncoderExt_encodeRaw, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO(0, errorCorrectionLevel, BackedEnum, 0)
ZEND_END_ARG_INFO()

/* Method entries for NativeEncoderExt */
static const zend_function_entry native_encoder_methods[] = {
    PHP_ME(NativeEncoderExt, encodeRaw, arginfo_NativeEncoderExt_encodeRaw, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* Object handlers */
static zend_object_handlers scanme_qr_native_encoder_handlers;

/*
 * Create NativeEncoder object
 */
zend_object *scanme_qr_create_native_encoder(void)
{
    return scanme_qr_native_encoder_create(scanme_qr_native_encoder_ce);
}

/*
 * Create NativeEncoder object handler
 */
static zend_object *scanme_qr_native_encoder_create(zend_class_entry *class_type)
{
    scanme_qr_native_encoder_object *intern;

    intern = zend_object_alloc(sizeof(scanme_qr_native_encoder_object), class_type);
    zend_object_std_init(&intern->std, class_type);
    object_properties_init(&intern->std, class_type);

    intern->std.handlers = &scanme_qr_native_encoder_handlers;

    return &intern->std;
}

/*
 * Free NativeEncoder object handler
 */
static void scanme_qr_native_encoder_free(zend_object *object)
{
    zend_object_std_dtor(object);
}

/*
 * NativeEncoderExt::encodeRaw() method
 *
 * public function encodeRaw(string $url, ErrorCorrectionLevel $errorCorrectionLevel): array
 * Returns: ['version' => int, 'size' => int, 'data' => bool[]]
 */
static PHP_METHOD(NativeEncoderExt, encodeRaw)
{
    zend_string *url;
    zval *ecl_obj;
    zend_long ecl_value;
    
    /* Parse parameters */
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(url)
        Z_PARAM_OBJECT(ecl_obj)
    ZEND_PARSE_PARAMETERS_END();

    /* Validate URL is not empty */
    if (ZSTR_LEN(url) == 0) {
        zend_throw_exception(zend_ce_exception, "Data cannot be empty", 0);
        RETURN_THROWS();
    }

    /* Check if the object is an enum */
    zend_class_entry *ecl_ce = Z_OBJCE_P(ecl_obj);
    if (!(ecl_ce->ce_flags & ZEND_ACC_ENUM)) {
        zend_throw_exception(zend_ce_exception, "Second argument must be an ErrorCorrectionLevel enum", 0);
        RETURN_THROWS();
    }

    /* Get ErrorCorrectionLevel value (0=Low, 1=Medium, 2=Quartile, 3=High) */
    zval retval;
    
    /* Access $ecl_obj->value property (BackedEnum) */
    zval *value_prop = zend_read_property(ecl_ce, Z_OBJ_P(ecl_obj), "value", sizeof("value") - 1, 0, &retval);
    
    if (value_prop == NULL || Z_TYPE_P(value_prop) != IS_LONG) {
        zval_ptr_dtor(&retval);
        zend_throw_exception(zend_ce_exception, "ErrorCorrectionLevel value must be an integer", 0);
        RETURN_THROWS();
    }
    
    ecl_value = Z_LVAL_P(value_prop);
    zval_ptr_dtor(&retval);

    /* Validate ECL value */
    if (ecl_value < 0 || ecl_value > 3) {
        zend_throw_exception(zend_ce_exception, "Invalid ErrorCorrectionLevel value", 0);
        RETURN_THROWS();
    }

    /* Call C library encode function */
    scanme_qr_result_t result;
    int ret = scanme_qr_encode(ZSTR_VAL(url), ZSTR_LEN(url), (int)ecl_value, &result);

    if (ret != 0) {
        scanme_qr_result_free(&result);
        zend_throw_exception(zend_ce_exception, "Failed to encode QR code", ret);
        RETURN_THROWS();
    }

    /* Return array: ['version' => int, 'size' => int, 'data' => bool[]] */
    array_init(return_value);
    
    /* Add version */
    add_assoc_long(return_value, "version", result.version);
    
    /* Add size */
    add_assoc_long(return_value, "size", result.size);
    
    /* Add data array */
    zval data_array;
    array_init(&data_array);
    
    int total_modules = result.size * result.size;
    for (int i = 0; i < total_modules; i++) {
        add_next_index_bool(&data_array, result.modules[i] != 0);
    }
    
    add_assoc_zval(return_value, "data", &data_array);

    /* Free C library result */
    scanme_qr_result_free(&result);
}

/*
 * Register NativeEncoderExt class
 */
void scanme_qr_register_native_encoder(zend_class_entry *parent_ce)
{
    zend_class_entry ce;

    INIT_CLASS_ENTRY(ce, "CrazyGoat\\ScanMePHP\\NativeEncoderExt", native_encoder_methods);
    scanme_qr_native_encoder_ce = zend_register_internal_class(&ce);
    scanme_qr_native_encoder_ce->create_object = scanme_qr_native_encoder_create;
    scanme_qr_native_encoder_ce->ce_flags |= ZEND_ACC_FINAL;

    memcpy(&scanme_qr_native_encoder_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    scanme_qr_native_encoder_handlers.free_obj = scanme_qr_native_encoder_free;
    scanme_qr_native_encoder_handlers.offset = XtOffsetOf(scanme_qr_native_encoder_object, std);
}
