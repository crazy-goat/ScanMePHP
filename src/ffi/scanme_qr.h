typedef struct {
    uint8_t* modules;
    int      size;
    int      version;
} scanme_qr_result_t;

int scanme_qr_encode(
    const char*         data,
    size_t              len,
    int                 ecl,
    scanme_qr_result_t* out
);

void scanme_qr_result_free(scanme_qr_result_t* out);

const char* scanme_qr_version(void);
