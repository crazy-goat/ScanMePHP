cmake_minimum_required(VERSION 3.16)
project(scanme_qr VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

option(BUILD_TESTS "Build tests" ON)

include(CheckCXXCompilerFlag)

set(LIB_SOURCES
    src/encoder.cpp
    src/reed_solomon.cpp
    src/matrix.cpp
    src/mask.cpp
)

if(NOT CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|ARM64")
    check_cxx_compiler_flag("-msse2" HAVE_SSE2)
    check_cxx_compiler_flag("-mavx2" HAVE_AVX2)
    check_cxx_compiler_flag("-mavx512f -mavx512bw -mavx512vpopcntdq" HAVE_AVX512)

    if(HAVE_SSE2)
        add_compile_definitions(BUILD_SSE2)
    endif()

    if(HAVE_AVX2)
        add_compile_definitions(BUILD_AVX2)
    endif()

    if(HAVE_AVX512)
        add_compile_definitions(BUILD_AVX512)
    endif()
endif()

if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|ARM64")
    add_compile_definitions(BUILD_NEON)
endif()

add_library(scanme_qr SHARED ${LIB_SOURCES})

target_include_directories(scanme_qr
    PUBLIC include
    PRIVATE src
)

target_compile_options(scanme_qr PRIVATE
    -Wall -Wextra -Werror
    $<$<CONFIG:Release>:-O3>
)

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
