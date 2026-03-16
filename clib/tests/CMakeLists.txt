add_executable(test_encoder test_encoder.cpp)
target_link_libraries(test_encoder PRIVATE scanme_qr)
target_include_directories(test_encoder PRIVATE ${CMAKE_SOURCE_DIR}/include)
add_test(NAME test_encoder COMMAND test_encoder)
