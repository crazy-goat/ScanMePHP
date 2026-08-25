cmake_minimum_required(VERSION 3.16)
project(scanme_qr VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

option(BUILD_TESTS "Build tests" ON)
option(BUILD_BENCH "Build benchmark" OFF)

include(CheckCXXCompilerFlag)

set(LIB_SOURCES
    src/encoder.cpp
    src/reed_solomon.cpp
    src/matrix.cpp
    src/mask.cpp
    src/mask_kernel_generic.cpp
)

# The mask-penalty kernel (mask_kernel.hpp) is plain C++ written so the
# compiler auto-vectorizes it. On x86-64 the baseline is SSE2 without POPCNT,
# so we additionally compile the same kernel for AVX2 and AVX-512 and pick one
# at runtime (see mask.cpp). One binary then runs well on any VPS CPU.
# On aarch64 NEON is baseline and a single build is enough.
set(SCANME_X86 FALSE)
if(CMAKE_OSX_ARCHITECTURES)
    if(CMAKE_OSX_ARCHITECTURES MATCHES "x86_64")
        set(SCANME_X86 TRUE)
    endif()
elseif(CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|AMD64|amd64")
    set(SCANME_X86 TRUE)
endif()

if(SCANME_X86 AND NOT MSVC)
    check_cxx_compiler_flag("-mavx2 -mbmi2 -mpopcnt" HAVE_AVX2)
    check_cxx_compiler_flag("-mavx512f -mavx512bw -mavx512vl -mavx512vpopcntdq" HAVE_AVX512)
    if(HAVE_AVX2)
        list(APPEND LIB_SOURCES src/mask_kernel_avx2.cpp)
        set_source_files_properties(src/mask_kernel_avx2.cpp PROPERTIES
            COMPILE_OPTIONS "-mavx2;-mbmi;-mbmi2;-mpopcnt;-mlzcnt")
        add_compile_definitions(SCANME_HAVE_AVX2_KERNEL)
    endif()
    if(HAVE_AVX512)
        list(APPEND LIB_SOURCES src/mask_kernel_avx512.cpp)
        set_source_files_properties(src/mask_kernel_avx512.cpp PROPERTIES
            COMPILE_OPTIONS "-mavx512f;-mavx512bw;-mavx512vl;-mavx512vpopcntdq;-mbmi;-mbmi2;-mpopcnt;-mlzcnt")
        add_compile_definitions(SCANME_HAVE_AVX512_KERNEL)
    endif()
endif()

# Object library shared by the .so, the tests (which use internal symbols) and the bench.
add_library(scanme_qr_objs OBJECT ${LIB_SOURCES})
target_compile_definitions(scanme_qr_objs PRIVATE SCANME_QR_EXPORTS)
target_include_directories(scanme_qr_objs
    PUBLIC include
    PRIVATE src
)
target_compile_options(scanme_qr_objs PRIVATE
    $<$<NOT:$<CXX_COMPILER_ID:MSVC>>:-Wall -Wextra -Werror>
    # /wd4324: the kernels deliberately over-align their row buffers, and MSVC
    # warns on every such struct ("padded due to alignment specifier"). The
    # padding is the point, so the warning is pure noise here.
    $<$<CXX_COMPILER_ID:MSVC>:/W4 /WX /wd4324>
    $<$<CONFIG:Release>:$<IF:$<CXX_COMPILER_ID:MSVC>,/O2,-O3>>
)
# MSVC deprecates std::getenv in favour of the non-portable _dupenv_s; the one
# call site reads an optional test/bench override once at startup.
target_compile_definitions(scanme_qr_objs PRIVATE
    $<$<CXX_COMPILER_ID:MSVC>:_CRT_SECURE_NO_WARNINGS>
)
set_target_properties(scanme_qr_objs PROPERTIES
    CXX_VISIBILITY_PRESET hidden
    VISIBILITY_INLINES_HIDDEN ON
    POSITION_INDEPENDENT_CODE ON
)

add_library(scanme_qr SHARED $<TARGET_OBJECTS:scanme_qr_objs>)
target_include_directories(scanme_qr PUBLIC include)

set_target_properties(scanme_qr PROPERTIES
    CXX_VISIBILITY_PRESET hidden
    VISIBILITY_INLINES_HIDDEN ON
    POSITION_INDEPENDENT_CODE ON
)

if(BUILD_TESTS)
    enable_testing()
    add_subdirectory(tests)
endif()

if(BUILD_BENCH)
    add_subdirectory(bench)
endif()
