<?php
  // Define missing keys
  define("NCURSES_KEY_CARRIAGE_RETURN", 13);
  define("NCURSES_KEY_DEL", 127);
  define("NCURSES_KEY_LINE_FEED", 10);

  class Shell {
    // History & scrollback variables
    private static $commands           = array();
    private static $history            = array();
    private static $scrollback         = array(null);
    private static $scrollbackPosition = 0;

    // Prompt & line preview variables
    private static $cursorMargin       = 10;
    private static $lineColumn         = 0;
    private static $linePosition       = 0;
    private static $lineSize           = 0;
    private static $prompt             = null;

    // Main window & dimension variables
    private static $mainWindow     = null;
    private static $mainWindowCols = 0;
    private static $mainWindowRows = 0;

    // Output window & dimension variables
    private static $outputWindow     = null;
    private static $outputWindowCols = 0;
    private static $outputWindowRows = 0;

    // Content array for output window
    private static $outputBuffer = array();

    public static function begin($prompt = null) {
      // Register a signal handler for SIGINT
      declare(ticks = 1);
      pcntl_signal(SIGINT, function() {
        if (strlen(self::getCurrentLine()) == 0)
          self::appendOutput("Use 'exit' to end the shell.");
        // Clear the most recent unsubmitted command and reset the scrollback
        // position index
        self::$scrollback[self::$scrollbackPosition = 0] = null;
        // Update the input line
        self::updateInput();
      });
      // Register a shutdown function
      register_shutdown_function(array('Shell', 'end'));

      // Initialize ncurses
      ncurses_init();
      // Disable the display of user input
      ncurses_noecho();
      // Make the cursor visible
      ncurses_curs_set(2);

      // Create the main window
      self::$mainWindow = ncurses_newwin(0, 0, 0, 0);
      // Get the max coordinates for the main window
      ncurses_getmaxyx(self::$mainWindow, self::$mainWindowRows,
        self::$mainWindowCols);

      // Create the output window
      self::$outputWindow = ncurses_newwin(self::$mainWindowRows - 1,
        self::$mainWindowCols, 0, 0);
      // Get the max coordinates for the output window
      ncurses_getmaxyx(self::$outputWindow, self::$outputWindowRows,
        self::$outputWindowCols);

      // Copy the prompt string
      self::setPrompt($prompt);
      // Update the input line
      self::updateInput();
    }

    public static function end() {
      // Cleanly end the ncurses session
      ncurses_end();
    }

    public static function appendOutput($msg) {
      // Split the message based on length
      $msg = str_split($msg, self::$mainWindowCols - 1);
      // Merge the given lines into the output buffer
      self::$outputBuffer = array_merge(self::$outputBuffer, $msg);
      // Remove oldest lines that are out of bounds
      while (count(self::$outputBuffer) > self::$outputWindowRows)
        array_shift(self::$outputBuffer);
      // Update the output window
      self::updateOutput();
    }

    private static function correctTextPreview() {
      // Reset the line column position if it's out of bounds
      if (self::$lineColumn > strlen(self::getCurrentLine()))
        self::resetLinePosition();

      // If we have a larger line than the allowed line size ...
      if (strlen(self::getCurrentLine()) > self::$lineSize) {
        // Determine if the left margin is out of bounds
        $leftMarginOOB    = self::getPreviewCursor() < self::$cursorMargin;
        // Determine if the right margin is out of bounds
        $rightMarginOOB   = self::$lineSize - self::getPreviewCursor() <
                            self::$cursorMargin;
        // Determine if the cursor is out of bounds
        $previewCursorOOB = self::getPreviewCursor() > self::$lineSize;
        // Determine if the preview length is out of bounds
        $previewLengthOOB = strlen(self::getLinePreview()) > self::$lineSize;

        // If the left margin is out of bounds, decrement if possible
        if (self::$linePosition > 0 && $leftMarginOOB)
          --self::$linePosition;

        // If the right margin is out of bounds, and either the preview cursor
        // or preview length is out of bounds, then increment the line position
        if ($rightMarginOOB && ($previewCursorOOB || $previewLengthOOB))
          ++self::$linePosition;
      }
      else {
        // We have a small line; set the line position to zero
        self::$linePosition = 0;
      }
    }

    private static function deleteCharacter() {
      if (self::$lineColumn > 0) {
        $line = self::getCurrentLine();
        // Remove the character behind the cursor and decrement column
        $line = substr($line, 0, self::$lineColumn - 1).substr($line,
          self::$lineColumn--);
        self::setCurrentLine($line);
      }
    }

    private static function getCurrentLine() {
      return self::$scrollback[self::$scrollbackPosition];
    }

    public static function getLineLength() {
      return self::$mainWindowCols - 1;
    }

    private static function getLinePreview() {
      // Calculate the text to show according to line position and size
      $line = self::getCurrentLine();
      $line = substr($line, self::$linePosition, self::$lineSize);
      // Determine if there is text remaining to the left
      if (self::$linePosition != 0)
        $line  = '$'.substr($line, 1);
      // Determine if there is text remaining to the right
      if (self::$linePosition + self::$lineSize <
          strlen(self::getCurrentLine()))
        $line .= '$';
      return $line;
    }

    private static function getPreviewCursor() {
      // Calculate the current preview cursor position according to line column
      // and line position
      return self::$lineColumn - self::$linePosition;
    }

    private static function insertCharacter($c) {
      $line = self::getCurrentLine();
      // Insert the character at the current column and advance column
      $line = substr($line, 0, self::$lineColumn).$c.substr($line,
        self::$lineColumn++);
      self::setCurrentLine($line);
    }

    private static function moveInputCursor($col = 0) {
      // Make sure the column is within bounds
      if ($col < 0) $col = 0;
      if ($col >= self::$mainWindowCols)
        $col = self::$mainWindowCols - 1;
      // Move the input cursor
      ncurses_move(self::$mainWindowRows - 1, $col);
    }

    private static function moveOutputCursor($row = 0, $col = 0) {
      // Make sure the row is within bounds
      if ($row < 0) $row = 0;
      if ($row >= self::$mainWindowRows)
        $row = self::$mainWindowRows - 1;
      // Make sure the column is within bounds
      if ($col < 0) $col = 0;
      if ($col >= self::$mainWindowCols)
        $col = self::$mainWindowCols - 1;
      // Move the output cursor
      ncurses_wmove(self::$outputWindow, $row, $col);
    }

    public static function processInput() {
      $read = array(STDIN);
      $null = null;
      // Ensure that there is data to be read on STDIN
      while (stream_select($read, $null, $null, 0)) {
        // Grab a single character from STDIN
        $c = ncurses_getch();
        if ($c == NCURSES_KEY_DEL)
          // Remove a character from the current line
          self::deleteCharacter();
        elseif ($c == NCURSES_KEY_CARRIAGE_RETURN ||
                $c == NCURSES_KEY_LINE_FEED)
          // Submit the current line as a command
          self::submitCommand();
        elseif ($c == NCURSES_KEY_DOWN)
          // Scroll up in history
          self::scrollDown();
        elseif ($c == NCURSES_KEY_UP)
          // Scroll down in history
          self::scrollUp();
        elseif ($c == NCURSES_KEY_LEFT)
          // Decrement scrollback column position
          self::$lineColumn -= (self::$lineColumn > 0 ? 1 : 0);
        elseif ($c == NCURSES_KEY_RIGHT)
          // Advance scrollback column position
          self::$lineColumn += (self::$lineColumn < strlen(
            self::getCurrentLine()) ? 1 : 0);
        elseif ($c != NULL)
          // Add the character to the current line
          self::insertCharacter(chr($c));
        // Update the input buffer display
        self::updateInput();
      }
    }

    public static function registerCommand($command, $function) {
      // If this command hasn't been registered yet, register it
      if (!isset(self::$commands[strtolower($command)]))
        self::$commands[strtolower($command)] = $function;
    }

    private static function resetLinePosition() {
      // Reset the cursor position to the length of the current line
      self::$lineColumn = strlen(self::getCurrentLine());
      // Calculate the line position based off of length of text
      if (self::$lineColumn > self::$lineSize)
        self::$linePosition = self::$lineColumn - self::$lineSize;
      else
        self::$linePosition = 0;
    }

    private static function scrollDown() {
      if (self::$scrollbackPosition > 0)
        // Decrease the current scrollback position
        --self::$scrollbackPosition;
      // Reset line positioning for scrolling events
      self::resetLinePosition();
    }

    private static function scrollUp() {
      if (self::$scrollbackPosition + 1 < count(self::$scrollback))
        // Increase the current scrollback position
        ++self::$scrollbackPosition;
      // Reset line positioning for scrolling events
      self::resetLinePosition();
    }

    private static function setCurrentLine($text) {
      // Assign the given text to the item at the current scrollback position
      self::$scrollback[self::$scrollbackPosition] = $text;
    }

    public static function setPrompt($prompt) {
      // Update the prompt
      self::$prompt   = $prompt;
      // Update the line preview size
      self::$lineSize = self::$mainWindowCols - strlen(self::$prompt) - 1;
      // Check if we should enter panic mode
      self::sizePanic();
    }

    private static function sizePanic() {
      // Get width and height for standard screen
      ncurses_getmaxyx(STDSCR, $aheight, $awidth);
      // Calculate the minimum width for a shell session
      $width = strlen(self::$prompt) + (self::$cursorMargin * 2) + 1;
      // Exit if actual width is less than required width
      if ($awidth < $width) {
        self::end();
        exit(1);
      }
    }

    private static function submitCommand() {
      if (strlen(self::getCurrentLine()) > 0) {
        // Push the current line to the history
        array_unshift(self::$history, trim(self::getCurrentLine()));
        // Replace the current scrollback array with the history array
        self::$scrollback = array_values(self::$history);
        // Add an empty line to the scrollback to act as a new line
        array_unshift(self::$scrollback, null);
        // Reset the cursor and scrollback position
        self::$lineColumn = self::$scrollbackPosition = 0;

        // Fetch the last command
        $args = explode(" ", self::$history[0]);
        $cmd  = array_shift($args);
        if (strtolower($cmd) == 'exit' || strtolower($cmd) == 'quit') {
          // End the shell session upon request
          self::end();
          exit(0);
        }
        // Check if the command exists
        if (isset(self::$commands[strtolower($cmd)])) {
          // Run the callback associated with the command
          $callback = self::$commands[strtolower($cmd)];
          $callback($args);
        }
        else {
          // The command doesn't exist; inform the user
          self::appendOutput("Unknown command: ".escapeshellarg($cmd));
        }
      }
    }

    private static function updateInput() {
      // Move the input cursor to its origin
      self::moveInputCursor();
      // Clear the current line
      ncurses_clrtoeol();
      // Print the prompt
      ncurses_addstr(self::$prompt);

      // Correct the prompt text preview window
      self::correctTextPreview();

      // Show the calculated text on the input line
      ncurses_addstr(self::getLinePreview());
      // Move the input cursor into position [needed for text scrolling]
      self::moveInputCursor(strlen(self::$prompt) + self::getPreviewCursor());
      // Refresh the main window
      ncurses_refresh();
    }

    private static function updateOutput() {
      // Clear the output window
      ncurses_wclear(self::$outputWindow);
      // Move the output cursor to its origin
      self::moveOutputCursor();
      // Print each line in the output buffer to the output window
      foreach (self::$outputBuffer as $line)
        ncurses_waddstr(self::$outputWindow, $line."\n");
      // Refresh the output window
      ncurses_wrefresh(self::$outputWindow);
    }
  }
