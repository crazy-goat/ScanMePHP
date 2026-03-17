add_executable(test_encoder test_encoder.cpp)
target_link_libraries(test_encoder PRIVATE scanme_qr)
target_include_directories(test_encoder PRIVATE ${CMAKE_SOURCE_DIR}/include)
add_test(NAME test_encoder COMMAND test_encoder)

add_executable(test_debug_mask
    test_debug_mask.cpp
    ${CMAKE_SOURCE_DIR}/src/encoder.cpp
    ${CMAKE_SOURCE_DIR}/src/reed_solomon.cpp
    ${CMAKE_SOURCE_DIR}/src/matrix.cpp
    ${CMAKE_SOURCE_DIR}/src/mask.cpp
)
target_include_directories(test_debug_mask PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_debug_mask COMMAND test_debug_mask)

add_executable(test_nayuki_compare
    test_nayuki_compare.cpp
    nayuki/qrcodegen.cpp
    ${CMAKE_SOURCE_DIR}/src/encoder.cpp
    ${CMAKE_SOURCE_DIR}/src/reed_solomon.cpp
    ${CMAKE_SOURCE_DIR}/src/matrix.cpp
    ${CMAKE_SOURCE_DIR}/src/mask.cpp
)
target_include_directories(test_nayuki_compare PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_nayuki_compare COMMAND test_nayuki_compare)

enable_language(C)
add_executable(test_nayuki_c_vs_cpp
    test_nayuki_c_vs_cpp.cpp
    nayuki/qrcodegen.cpp
    nayuki/qrcodegen_c.c
)
target_include_directories(test_nayuki_c_vs_cpp PRIVATE ${CMAKE_SOURCE_DIR}/include)
add_test(NAME test_nayuki_c_vs_cpp COMMAND test_nayuki_c_vs_cpp)

add_executable(test_zigzag_trace
    test_zigzag_trace.cpp
    nayuki/qrcodegen.cpp
    ${CMAKE_SOURCE_DIR}/src/encoder.cpp
    ${CMAKE_SOURCE_DIR}/src/reed_solomon.cpp
    ${CMAKE_SOURCE_DIR}/src/matrix.cpp
    ${CMAKE_SOURCE_DIR}/src/mask.cpp
)
target_include_directories(test_zigzag_trace PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_zigzag_trace COMMAND test_zigzag_trace)

add_executable(test_nayuki_exhaustive
    test_nayuki_exhaustive.cpp
    nayuki/qrcodegen.cpp
    ${CMAKE_SOURCE_DIR}/src/encoder.cpp
    ${CMAKE_SOURCE_DIR}/src/reed_solomon.cpp
    ${CMAKE_SOURCE_DIR}/src/matrix.cpp
    ${CMAKE_SOURCE_DIR}/src/mask.cpp
)
target_include_directories(test_nayuki_exhaustive PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_nayuki_exhaustive COMMAND test_nayuki_exhaustive)

add_executable(test_csv_fixtures
    test_csv_fixtures.cpp
    ${CMAKE_SOURCE_DIR}/src/encoder.cpp
    ${CMAKE_SOURCE_DIR}/src/reed_solomon.cpp
    ${CMAKE_SOURCE_DIR}/src/matrix.cpp
    ${CMAKE_SOURCE_DIR}/src/mask.cpp
)
target_include_directories(test_csv_fixtures PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_csv_fixtures COMMAND test_csv_fixtures ${CMAKE_SOURCE_DIR}/../tests/fixtures/qr_reference.csv)
