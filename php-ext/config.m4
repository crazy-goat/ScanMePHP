dnl $Id$
dnl config.m4 for extension scanme_qr

dnl If your extension references something external, use with:

PHP_ARG_WITH([scanme_qr],
  [for scanme_qr support],
  [AS_HELP_STRING([--with-scanme_qr[=DIR]],[Include scanme_qr support. DIR is the path to clib/ directory.])],
  [no])

if test "$PHP_SCANME_QR" != "no"; then

  dnl Set the path to clib
  if test "$PHP_SCANME_QR" = "yes"; then
    SCANME_QR_DIR="$abs_srcdir/../clib"
  else
    SCANME_QR_DIR="$PHP_SCANME_QR"
  fi

  dnl Check if clib headers exist
  if test ! -f "$SCANME_QR_DIR/include/scanme_qr.h"; then
    AC_MSG_ERROR([scanme_qr.h not found in $SCANME_QR_DIR/include/])
  fi

  dnl Check if clib library exists (try both .so and .a)
  SCANME_QR_LIB=""
  if test -f "$SCANME_QR_DIR/build/libscanme_qr.so"; then
    SCANME_QR_LIB="$SCANME_QR_DIR/build/libscanme_qr.so"
  elif test -f "$SCANME_QR_DIR/build/libscanme_qr.a"; then
    SCANME_QR_LIB="$SCANME_QR_DIR/build/libscanme_qr.a"
  else
    AC_MSG_ERROR([libscanme_qr.so or libscanme_qr.a not found in $SCANME_QR_DIR/build/])
  fi

  AC_MSG_RESULT([Using scanme_qr library from: $SCANME_QR_DIR])
  AC_MSG_RESULT([Linking against: $SCANME_QR_LIB])

  dnl Add include path for clib headers
  PHP_ADD_INCLUDE([$SCANME_QR_DIR/include])

  dnl Add library path and link against scanme_qr
  PHP_ADD_LIBRARY_WITH_PATH(scanme_qr, [$SCANME_QR_DIR/build], SCANME_QR_SHARED_LIBADD)

  dnl Require C++ compiler for linking
  PHP_REQUIRE_CXX()

  dnl Source files for the extension
  SCANME_QR_SOURCES="
    scanme_qr.c
    native_encoder.c
  "

  dnl Build the extension
  PHP_NEW_EXTENSION([scanme_qr], [$SCANME_QR_SOURCES], [$ext_shared],, [-I$SCANME_QR_DIR/include], CXX)

  dnl Link against the C++ library
  PHP_SUBST([SCANME_QR_SHARED_LIBADD])
fi
