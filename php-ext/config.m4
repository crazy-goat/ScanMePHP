dnl $Id$
dnl config.m4 for extension scanmeqr

PHP_ARG_WITH([scanmeqr],
  [for scanmeqr support],
  [AS_HELP_STRING([--with-scanmeqr[=DIR]],[Include scanmeqr support. DIR is the path to clib/ directory.])],
  [no])

if test "$PHP_SCANMEQR" != "no"; then

  dnl Set the path to clib
  if test "$PHP_SCANMEQR" = "yes"; then
    SCANMEQR_DIR="$abs_srcdir/../clib"
  else
    SCANMEQR_DIR="$PHP_SCANMEQR"
  fi

  dnl Check if clib headers exist
  if test ! -f "$SCANMEQR_DIR/include/scanme_qr.h"; then
    AC_MSG_ERROR([scanme_qr.h not found in $SCANMEQR_DIR/include/])
  fi

  dnl Check if clib library exists
  SCANMEQR_LIB=""
  if test -f "$SCANMEQR_DIR/build/libscanme_qr.so"; then
    SCANMEQR_LIB="$SCANMEQR_DIR/build/libscanme_qr.so"
  elif test -f "$SCANMEQR_DIR/build/libscanme_qr.a"; then
    SCANMEQR_LIB="$SCANMEQR_DIR/build/libscanme_qr.a"
  elif test -f "$SCANMEQR_DIR/build/libscanme_qr.dylib"; then
    SCANMEQR_LIB="$SCANMEQR_DIR/build/libscanme_qr.dylib"
  else
    AC_MSG_ERROR([libscanme_qr.so, libscanme_qr.a, or libscanme_qr.dylib not found in $SCANMEQR_DIR/build/])
  fi

  AC_MSG_RESULT([Using scanmeqr library from: $SCANMEQR_DIR])
  AC_MSG_RESULT([Linking against: $SCANMEQR_LIB])

  dnl Add include path
  PHP_ADD_INCLUDE([$SCANMEQR_DIR/include])

  dnl Add library path and link
  PHP_ADD_LIBRARY_WITH_PATH(scanme_qr, [$SCANMEQR_DIR/build], SCANMEQR_SHARED_LIBADD)

  PHP_REQUIRE_CXX()

  SCANMEQR_SOURCES="
    scanme_qr.c
  "

  PHP_NEW_EXTENSION([scanmeqr], [$SCANMEQR_SOURCES], [$ext_shared],, [-I$SCANMEQR_DIR/include], CXX)

  PHP_SUBST([SCANMEQR_SHARED_LIBADD])
fi
