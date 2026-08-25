# Micro-benchmark for the C++ encoder. Links the object files directly so it can
# time individual pipeline stages (internal symbols are hidden in the shared lib).
add_executable(scanme_bench bench.cpp $<TARGET_OBJECTS:scanme_qr_objs>)
target_include_directories(scanme_bench PRIVATE ${CMAKE_SOURCE_DIR}/include ${CMAKE_SOURCE_DIR}/src)
target_compile_options(scanme_bench PRIVATE $<$<CONFIG:Release>:$<IF:$<CXX_COMPILER_ID:MSVC>,/O2,-O3>>)
