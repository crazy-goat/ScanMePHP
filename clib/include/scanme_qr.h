#ifndef SCANME_QR_H
#define SCANME_QR_H

#include <stddef.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

#ifdef _WIN32
  #ifdef SCANME_QR_EXPORTS
    #define SCANME_QR_API __declspec(dllexport)
  #else
    #define SCANME_QR_API __declspec(dllimport)
  #endif
#else
  #define SCANME_QR_API __attribute__((visibility("default")))
#endif

typedef struct {
    uint8_t* modules;
    int      size;
    int      version;
} scanme_qr_result_t;

SCANME_QR_API int scanme_qr_encode(
    const char*         data,
    size_t              len,
    int                 ecl,
    scanme_qr_result_t* out
);

SCANME_QR_API void scanme_qr_result_free(scanme_qr_result_t* out);

SCANME_QR_API const char* scanme_qr_version(void);

#ifdef __cplusplus
}
#endif

#endif /* SCANME_QR_H */
