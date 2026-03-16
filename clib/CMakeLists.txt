cmake_minimum_required(VERSION 3.16)
project(scanme_qr VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

option(BUILD_TESTS "Build tests" ON)

include(CheckCXXCompilerFlag)

set(SIMD_SOURCES src/simd/penalty_scalar.cpp)

if(NOT CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|ARM64")
    check_cxx_compiler_flag("-msse2"              HAVE_SSE2)
    check_cxx_compiler_flag("-msse4.2"            HAVE_SSE42)
    check_cxx_compiler_flag("-mavx2"              HAVE_AVX2)
    check_cxx_compiler_flag("-mavx512f -mavx512bw" HAVE_AVX512)

    if(HAVE_SSE2)
        list(APPEND SIMD_SOURCES src/simd/penalty_sse2.cpp)
        set_source_files_properties(src/simd/penalty_sse2.cpp
            PROPERTIES COMPILE_FLAGS "-msse2")
        add_compile_definitions(BUILD_SSE2)
    endif()

    if(HAVE_SSE42)
        list(APPEND SIMD_SOURCES src/simd/penalty_sse42.cpp)
        set_source_files_properties(src/simd/penalty_sse42.cpp
            PROPERTIES COMPILE_FLAGS "-msse4.2")
        add_compile_definitions(BUILD_SSE42)
    endif()

    if(HAVE_AVX2)
        list(APPEND SIMD_SOURCES src/simd/penalty_avx2.cpp)
        set_source_files_properties(src/simd/penalty_avx2.cpp
            PROPERTIES COMPILE_FLAGS "-mavx2")
        add_compile_definitions(BUILD_AVX2)
    endif()

    if(HAVE_AVX512)
        list(APPEND SIMD_SOURCES src/simd/penalty_avx512.cpp)
        set_source_files_properties(src/simd/penalty_avx512.cpp
            PROPERTIES COMPILE_FLAGS "-mavx512f -mavx512bw")
        add_compile_definitions(BUILD_AVX512)
    endif()
endif()

if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|ARM64")
    list(APPEND SIMD_SOURCES src/simd/penalty_neon.cpp)
    add_compile_definitions(BUILD_NEON)
endif()

add_library(scanme_qr SHARED
    src/encoder.cpp
    src/reed_solomon.cpp
    src/matrix.cpp
    src/mask.cpp
    ${SIMD_SOURCES}
)

target_include_directories(scanme_qr
    PUBLIC include
    PRIVATE src
)

target_compile_options(scanme_qr PRIVATE
    -Wall -Wextra -Werror
    $<$<CONFIG:Release>:-O3>
)

# GCC 15 false positive: free-nonheap-object triggered by aggressive inlining of vector::push_back
# This is a known GCC bug (https://gcc.gnu.org/bugzilla/show_bug.cgi?id=109849)
if(CMAKE_CXX_COMPILER_ID STREQUAL "GNU" AND CMAKE_CXX_COMPILER_VERSION VERSION_GREATER_EQUAL "13")
    set_source_files_properties(src/encoder.cpp
        PROPERTIES COMPILE_FLAGS "-Wno-free-nonheap-object")
endif()

set_target_properties(scanme_qr PROPERTIES
    CXX_VISIBILITY_PRESET hidden
    VISIBILITY_INLINES_HIDDEN ON
    POSITION_INDEPENDENT_CODE ON
)

if(BUILD_TESTS)
    enable_testing()
    add_subdirectory(tests)
endif()
