add_executable(test_encoder test_encoder.cpp)
target_link_libraries(test_encoder PRIVATE scanme_qr)
target_include_directories(test_encoder PRIVATE ${CMAKE_SOURCE_DIR}/include)
add_test(NAME test_encoder COMMAND test_encoder)

# Uses internal scanme:: symbols (hidden in the shared lib), so link the objects directly.
add_executable(test_csv_fixtures test_csv_fixtures.cpp $<TARGET_OBJECTS:scanme_qr_objs>)
target_include_directories(test_csv_fixtures PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_csv_fixtures COMMAND test_csv_fixtures ${CMAKE_SOURCE_DIR}/../tests/fixtures/qr_agreement.csv)

add_executable(test_penalty_equivalence test_penalty_equivalence.cpp $<TARGET_OBJECTS:scanme_qr_objs>)
target_include_directories(test_penalty_equivalence PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
add_test(NAME test_penalty_equivalence COMMAND test_penalty_equivalence)
