/*
  +----------------------------------------------------------------------+
  | PHP Version 8.1+                                                     |
  +----------------------------------------------------------------------+
  | Copyright (c) 2024 Crazy Goat                                        |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Crazy Goat <crazy@goat.com>                                  |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_scanme_qr.h"
#include "native_encoder.h"
#include "scanme_qr.h"

/* For compatibility with older PHP versions */
#ifndef ZEND_ACC_FINAL
#define ZEND_ACC_FINAL ZEND_ACC_FINAL_CLASS
#endif

/* {{{ scanme_qr_functions[] */
static const zend_function_entry scanme_qr_functions[] = {
    PHP_FE_END
};
/* }}} */

/* Declare module functions */
static PHP_MINIT_FUNCTION(scanme_qr);
static PHP_MINFO_FUNCTION(scanme_qr);

/* {{{ scanme_qr_module_entry */
zend_module_entry scanme_qr_module_entry = {
    STANDARD_MODULE_HEADER,
    "scanme_qr",                    /* Extension name */
    scanme_qr_functions,            /* Function entries */
    PHP_MINIT(scanme_qr),           /* Module init */
    NULL,                           /* Module shutdown */
    NULL,                           /* Request init */
    NULL,                           /* Request shutdown */
    PHP_MINFO(scanme_qr),           /* Module info */
    PHP_SCANME_QR_VERSION,          /* Extension version */
    STANDARD_MODULE_PROPERTIES
};
/* }}} */

#ifdef COMPILE_DL_SCANME_QR
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(scanme_qr)
#endif

/* {{{ PHP_MINIT_FUNCTION */
static PHP_MINIT_FUNCTION(scanme_qr)
{
    /* Register NativeEncoder class */
    scanme_qr_register_native_encoder(NULL);

    return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION */
static PHP_MINFO_FUNCTION(scanme_qr)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "scanme_qr support", "enabled");
    php_info_print_table_row(2, "Extension version", PHP_SCANME_QR_VERSION);
    
    /* Get C library version */
    const char* lib_version = scanme_qr_version();
    php_info_print_table_row(2, "C library version", lib_version ? lib_version : "unknown");
    
    php_info_print_table_end();
}
/* }}} */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
