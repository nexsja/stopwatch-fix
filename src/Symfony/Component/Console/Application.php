<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console;

use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\DebugFormatterHelper;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * An Application is the container for a collection of commands.
 *
 * It is the main entry point of a Console application.
 *
 * This class is optimized for a standard CLI environment.
 *
 * Usage:
 *
 *     $app = new Application('myapp', '1.0 (stable)');
 *     $app->add(new SimpleCommand());
 *     $app->run();
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Application
{
    private $commands = array();
    private $wantHelps = false;
    private $runningCommand;
    private $name;
    private $version;
    private $commandLoader;
    private $catchExceptions = true;
    private $autoExit = true;
    private $definition;
    private $helperSet;
    private $dispatcher;
    private $terminal;
    private $defaultCommand;
    private $singleCommand;

    /**
     * @param string $name    The name of the application
     * @param string $version The version of the application
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->name = $name;
        $this->version = $version;
        $this->terminal = new Terminal();
        $this->defaultCommand = 'list';
        $this->helperSet = $this->getDefaultHelperSet();
        $this->definition = $this->getDefaultInputDefinition();

        foreach ($this->getDefaultCommands() as $command) {
            $this->add($command);
        }
    }

    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function setCommandLoader(CommandLoaderInterface $commandLoader)
    {
        $this->commandLoader = $commandLoader;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return int 0 if everything went fine, or an error code
     *
     * @throws \Exception When running fails. Bypass this when {@link setCatchExceptions()}.
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        putenv('LINES='.$this->terminal->getHeight());
        putenv('COLUMNS='.$this->terminal->getWidth());

        if (null === $input) {
            $input = new ArgvInput();
        }

        if (null === $output) {
            $output = new ConsoleOutput();
        }

        if (null !== $this->dispatcher && $this->dispatcher->hasListeners(ConsoleEvents::EXCEPTION)) {
            @trigger_error(sprintf('The "ConsoleEvents::EXCEPTION" event is deprecated since Symfony 3.3 and will be removed in 4.0. Listen to the "ConsoleEvents::ERROR" event instead.'), E_USER_DEPRECATED);
        }

        $this->configureIO($input, $output);

        try {
            $e = null;
            $exitCode = $this->doRun($input, $output);
        } catch (\Exception $x) {
            $e = $x;
        } catch (\Throwable $x) {
            $e = new FatalThrowableError($x);
        }

        if (null !== $e) {
            if (!$this->catchExceptions || !$x instanceof \Exception) {
                throw $x;
            }

            if ($output instanceof ConsoleOutputInterface) {
                $this->renderException($e, $output->getErrorOutput());
            } else {
                $this->renderException($e, $output);
            }

            $exitCode = $e->getCode();
            if (is_numeric($exitCode)) {
                $exitCode = (int) $exitCode;
                if (0 === $exitCode) {
                    $exitCode = 1;
                }
            } else {
                $exitCode = 1;
            }
        }

        if ($this->autoExit) {
            if ($exitCode > 255) {
                $exitCode = 255;
            }

            exit($exitCode);
        }

        return $exitCode;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return int 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(array('--version', '-V'), true)) {
            $output->writeln($this->getLongVersion());

            return 0;
        }

        $name = $this->getCommandName($input);
        if (true === $input->hasParameterOption(array('--help', '-h'), true)) {
            if (!$name) {
                $name = 'help';
                $input = new ArrayInput(array('command_name' => $this->defaultCommand));
            } else {
                $this->wantHelps = true;
            }
        }

        if (!$name) {
            $name = $this->defaultCommand;
            $input = new ArrayInput(array('command' => $this->defaultCommand));
        }

        try {
            $e = $this->runningCommand = null;
            // the command name MUST be the first element of the input
            $command = $this->find($name);
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        if (null !== $e) {
            if (null !== $this->dispatcher) {
                $event = new ConsoleErrorEvent($input, $output, $e);
                $this->dispatcher->dispatch(ConsoleEvents::ERROR, $event);
                $e = $event->getError();

                if (0 === $event->getExitCode()) {
                    return 0;
                }
            }

            throw $e;
        }

        $this->runningCommand = $command;
        $exitCode = $this->doRunCommand($command, $input, $output);
        $this->runningCommand = null;

        return $exitCode;
    }

    /**
     * Set a helper set to be used with the command.
     *
     * @param HelperSet $helperSet The helper set
     */
    public function setHelperSet(HelperSet $helperSet)
    {
        $this->helperSet = $helperSet;
    }

    /**
     * Get the helper set associated with the command.
     *
     * @return HelperSet The HelperSet instance associated with this command
     */
    public function getHelperSet()
    {
        return $this->helperSet;
    }

    /**
     * Set an input definition to be used with this application.
     *
     * @param InputDefinition $definition The input definition
     */
    public function setDefinition(InputDefinition $definition)
    {
        $this->definition = $definition;
    }

    /**
     * Gets the InputDefinition related to this Application.
     *
     * @return InputDefinition The InputDefinition instance
     */
    public function getDefinition()
    {
        if ($this->singleCommand) {
            $inputDefinition = $this->definition;
            $inputDefinition->setArguments();

            return $inputDefinition;
        }

        return $this->definition;
    }

    /**
     * Gets the help message.
     *
     * @return string A help message
     */
    public function getHelp()
    {
        return $this->getLongVersion();
    }

    /**
     * Gets whether to catch exceptions or not during commands execution.
     *
     * @return bool Whether to catch exceptions or not during commands execution
     */
    public function areExceptionsCaught()
    {
        return $this->catchExceptions;
    }

    /**
     * Sets whether to catch exceptions or not during commands execution.
     *
     * @param bool $boolean Whether to catch exceptions or not during commands execution
     */
    public function setCatchExceptions($boolean)
    {
        $this->catchExceptions = (bool) $boolean;
    }

    /**
     * Gets whether to automatically exit after a command execution or not.
     *
     * @return bool Whether to automatically exit after a command execution or not
     */
    public function isAutoExitEnabled()
    {
        return $this->autoExit;
    }

    /**
     * Sets whether to automatically exit after a command execution or not.
     *
     * @param bool $boolean Whether to automatically exit after a command execution or not
     */
    public function setAutoExit($boolean)
    {
        $this->autoExit = (bool) $boolean;
    }

    /**
     * Gets the name of the application.
     *
     * @return string The application name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the application name.
     *
     * @param string $name The application name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Gets the application version.
     *
     * @return string The application version
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Sets the application version.
     *
     * @param string $version The application version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Returns the long version of the application.
     *
     * @return string The long application version
     */
    public function getLongVersion()
    {
        if ('UNKNOWN' !== $this->getName()) {
            if ('UNKNOWN' !== $this->getVersion()) {
                return sprintf('%s <info>%s</info>', $this->getName(), $this->getVersion());
            }

            return $this->getName();
        }

        return 'Console Tool';
    }

    /**
     * Registers a new command.
     *
     * @param string $name The command name
     *
     * @return Command The newly created command
     */
    public function register($name)
    {
        return $this->add(new Command($name));
    }

    /**
     * Adds an array of command objects.
     *
     * If a Command is not enabled it will not be added.
     *
     * @param Command[] $commands An array of commands
     */
    public function addCommands(array $commands)
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
    }

    /**
     * Adds a command object.
     *
     * If a command with the same name already exists, it will be overridden.
     * If the command is not enabled it will not be added.
     *
     * @param Command $command A Command object
     *
     * @return Command|null The registered command if enabled or null
     */
    public function add(Command $command)
    {
        $command->setApplication($this);

        if (!$command->isEnabled()) {
            $command->setApplication(null);

            return;
        }

        if (null === $command->getDefinition()) {
            throw new LogicException(sprintf('Command class "%s" is not correctly initialized. You probably forgot to call the parent constructor.', get_class($command)));
        }

        if (!$command->getName()) {
            throw new LogicException(sprintf('The command defined in "%s" cannot have an empty name.', get_class($command)));
        }

        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }

        return $command;
    }

    /**
     * Returns a registered command by name or alias.
     *
     * @param string $name The command name or alias
     *
     * @return Command A Command object
     *
     * @throws CommandNotFoundException When given command name does not exist
     */
    public function get($name)
    {
        if (isset($this->commands[$name])) {
            $command = $this->commands[$name];
        } elseif ($this->commandLoader && $this->commandLoader->has($name)) {
            $command = $this->commandLoader->get($name);
            $this->add($command);
        } else {
            throw new CommandNotFoundException(sprintf('The command "%s" does not exist.', $name));
        }

        if ($this->wantHelps) {
            $this->wantHelps = false;

            $helpCommand = $this->get('help');
            $helpCommand->setCommand($command);

            return $helpCommand;
        }

        return $command;
    }

    /**
     * Returns true if the command exists, false otherwise.
     *
     * @param string $name The command name or alias
     *
     * @return bool true if the command exists, false otherwise
     */
    public function has($name)
    {
        return isset($this->commands[$name]) || ($this->commandLoader && $this->commandLoader->has($name));
    }

    /**
     * Returns an array of all unique namespaces used by currently registered commands.
     *
     * It does not return the global namespace which always exists.
     *
     * @return string[] An array of namespaces
     */
    public function getNamespaces()
    {
        $namespaces = array();
        foreach ($this->all() as $command) {
            $namespaces = array_merge($namespaces, $this->extractAllNamespaces($command->getName()));

            foreach ($command->getAliases() as $alias) {
                $namespaces = array_merge($namespaces, $this->extractAllNamespaces($alias));
            }
        }

        return array_values(array_unique(array_filter($namespaces)));
    }

    /**
     * Finds a registered namespace by a name or an abbreviation.
     *
     * @param string $namespace A namespace or abbreviation to search for
     *
     * @return string A registered namespace
     *
     * @throws CommandNotFoundException When namespace is incorrect or ambiguous
     */
    public function findNamespace($namespace)
    {
        $allNamespaces = $this->getNamespaces();
        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) { return preg_quote($matches[1]).'[^:]*'; }, $namespace);
        $namespaces = preg_grep('{^'.$expr.'}', $allNamespaces);

        if (empty($namespaces)) {
            $message = sprintf('There are no commands defined in the "%s" namespace.', $namespace);

            if ($alternatives = $this->findAlternatives($namespace, $allNamespaces)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }

                $message .= implode("\n    ", $alternatives);
            }

            throw new CommandNotFoundException($message, $alternatives);
        }

        $exact = in_array($namespace, $namespaces, true);
        if (count($namespaces) > 1 && !$exact) {
            throw new CommandNotFoundException(sprintf("The namespace \"%s\" is ambiguous.\nDid you mean one of these?\n%s", $namespace, $this->getAbbreviationSuggestions(array_values($namespaces))), array_values($namespaces));
        }

        return $exact ? $namespace : reset($namespaces);
    }

    /**
     * Finds a command by name or alias.
     *
     * Contrary to get, this command tries to find the best
     * match if you give it an abbreviation of a name or alias.
     *
     * @param string $name A command name or a command alias
     *
     * @return Command A Command instance
     *
     * @throws CommandNotFoundException When command name is incorrect or ambiguous
     */
    public function find($name)
    {
        $allCommands = $this->commandLoader ? array_merge($this->commandLoader->getNames(), array_keys($this->commands)) : array_keys($this->commands);
        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) { return preg_quote($matches[1]).'[^:]*'; }, $name);
        $commands = preg_grep('{^'.$expr.'}', $allCommands);

        if (empty($commands) || count(preg_grep('{^'.$expr.'$}', $commands)) < 1) {
            if (false !== $pos = strrpos($name, ':')) {
                // check if a namespace exists and contains commands
                $this->findNamespace(substr($name, 0, $pos));
            }

            $message = sprintf('Command "%s" is not defined.', $name);

            if ($alternatives = $this->findAlternatives($name, $allCommands)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }
                $message .= implode("\n    ", $alternatives);
            }

            throw new CommandNotFoundException($message, $alternatives);
        }

        // filter out aliases for commands which are already on the list
        if (count($commands) > 1) {
            $commandList = $this->commandLoader ? array_merge(array_flip($this->commandLoader->getNames()), $this->commands) : $this->commands;
            $commands = array_unique(array_filter($commands, function ($nameOrAlias) use ($commandList, $commands) {
                $commandName = $commandList[$nameOrAlias] instanceof Command ? $commandList[$nameOrAlias]->getName() : $nameOrAlias;

                return $commandName === $nameOrAlias || !in_array($commandName, $commands);
            }));
        }

        $exact = in_array($name, $commands, true);
        if (count($commands) > 1 && !$exact) {
            $usableWidth = $this->terminal->getWidth() - 10;
            $abbrevs = array_values($commands);
            $maxLen = 0;
            foreach ($abbrevs as $abbrev) {
                $maxLen = max(Helper::strlen($abbrev), $maxLen);
            }
            $abbrevs = array_map(function ($cmd) use ($commandList, $usableWidth, $maxLen) {
                if (!$commandList[$cmd] instanceof Command) {
                    return $cmd;
                }
                $abbrev = str_pad($cmd, $maxLen, ' ').' '.$commandList[$cmd]->getDescription();

                return Helper::strlen($abbrev) > $usableWidth ? Helper::substr($abbrev, 0, $usableWidth - 3).'...' : $abbrev;
            }, array_values($commands));
            $suggestions = $this->getAbbreviationSuggestions($abbrevs);

            throw new CommandNotFoundException(sprintf("Command \"%s\" is ambiguous.\nDid you mean one of these?\n%s", $name, $suggestions), array_values($commands));
        }

        return $this->get($exact ? $name : reset($commands));
    }

    /**
     * Gets the commands (registered in the given namespace if provided).
     *
     * The array keys are the full names and the values the command instances.
     *
     * @param string $namespace A namespace name
     *
     * @return Command[] An array of Command instances
     */
    public function all($namespace = null)
    {
        if (null === $namespace) {
            if (!$this->commandLoader) {
                return $this->commands;
            }

            $commands = $this->commands;
            foreach ($this->commandLoader->getNames() as $name) {
                if (!isset($commands[$name])) {
                    $commands[$name] = $this->get($name);
                }
            }

            return $commands;
        }

        $commands = array();
        foreach ($this->commands as $name => $command) {
            if ($namespace === $this->extractNamespace($name, substr_count($namespace, ':') + 1)) {
                $commands[$name] = $command;
            }
        }

        if ($this->commandLoader) {
            foreach ($this->commandLoader->getNames() as $name) {
                if (!isset($commands[$name]) && $namespace === $this->extractNamespace($name, substr_count($namespace, ':') + 1)) {
                    $commands[$name] = $this->get($name);
                }
            }
        }

        return $commands;
    }

    /**
     * Returns an array of possible abbreviations given a set of names.
     *
     * @param array $names An array of names
     *
     * @return array An array of abbreviations
     */
    public static function getAbbreviations($names)
    {
        $abbrevs = array();
        foreach ($names as $name) {
            for ($len = strlen($name); $len > 0; --$len) {
                $abbrev = substr($name, 0, $len);
                $abbrevs[$abbrev][] = $name;
            }
        }

        return $abbrevs;
    }

    /**
     * Renders a caught exception.
     *
     * @param \Exception      $e      An exception instance
     * @param OutputInterface $output An OutputInterface instance
     */
    public function renderException(\Exception $e, OutputInterface $output)
    {
        $output->writeln('', OutputInterface::VERBOSITY_QUIET);

        do {
            $title = sprintf(
                '  [%s%s]  ',
                get_class($e),
                $output->isVerbose() && 0 !== ($code = $e->getCode()) ? ' ('.$code.')' : ''
            );

            $len = Helper::strlen($title);

            $width = $this->terminal->getWidth() ? $this->terminal->getWidth() - 1 : PHP_INT_MAX;
            // HHVM only accepts 32 bits integer in str_split, even when PHP_INT_MAX is a 64 bit integer: https://github.com/facebook/hhvm/issues/1327
            if (defined('HHVM_VERSION') && $width > 1 << 31) {
                $width = 1 << 31;
            }
            $lines = array();
            foreach (preg_split('/\r?\n/', $e->getMessage()) as $line) {
                foreach ($this->splitStringByWidth($line, $width - 4) as $line) {
                    // pre-format lines to get the right string length
                    $lineLength = Helper::strlen($line) + 4;
                    $lines[] = array($line, $lineLength);

                    $len = max($lineLength, $len);
                }
            }

            $messages = array();
            $messages[] = $emptyLine = sprintf('<error>%s</error>', str_repeat(' ', $len));
            $messages[] = sprintf('<error>%s%s</error>', $title, str_repeat(' ', max(0, $len - Helper::strlen($title))));
            foreach ($lines as $line) {
                $messages[] = sprintf('<error>  %s  %s</error>', OutputFormatter::escape($line[0]), str_repeat(' ', $len - $line[1]));
            }
            $messages[] = $emptyLine;
            $messages[] = '';

            $output->writeln($messages, OutputInterface::VERBOSITY_QUIET);

            if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                $output->writeln('<comment>Exception trace:</comment>', OutputInterface::VERBOSITY_QUIET);

                // exception related properties
                $trace = $e->getTrace();
                array_unshift($trace, array(
                    'function' => '',
                    'file' => $e->getFile() !== null ? $e->getFile() : 'n/a',
                    'line' => $e->getLine() !== null ? $e->getLine() : 'n/a',
                    'args' => array(),
                ));

                for ($i = 0, $count = count($trace); $i < $count; ++$i) {
                    $class = isset($trace[$i]['class']) ? $trace[$i]['class'] : '';
                    $type = isset($trace[$i]['type']) ? $trace[$i]['type'] : '';
                    $function = $trace[$i]['function'];
                    $file = isset($trace[$i]['file']) ? $trace[$i]['file'] : 'n/a';
                    $line = isset($trace[$i]['line']) ? $trace[$i]['line'] : 'n/a';

                    $output->writeln(sprintf(' %s%s%s() at <info>%s:%s</info>', $class, $type, $function, $file, $line), OutputInterface::VERBOSITY_QUIET);
                }

                $output->writeln('', OutputInterface::VERBOSITY_QUIET);
            }
        } while ($e = $e->getPrevious());

        if (null !== $this->runningCommand) {
            $output->writeln(sprintf('<info>%s</info>', sprintf($this->runningCommand->getSynopsis(), $this->getName())), OutputInterface::VERBOSITY_QUIET);
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * Tries to figure out the terminal width in which this application runs.
     *
     * @return int|null
     *
     * @deprecated since version 3.2, to be removed in 4.0. Create a Terminal instance instead.
     */
    protected function getTerminalWidth()
    {
        @trigger_error(sprintf('%s is deprecated as of 3.2 and will be removed in 4.0. Create a Terminal instance instead.', __METHOD__), E_USER_DEPRECATED);

        return $this->terminal->getWidth();
    }

    /**
     * Tries to figure out the terminal height in which this application runs.
     *
     * @return int|null
     *
     * @deprecated since version 3.2, to be removed in 4.0. Create a Terminal instance instead.
     */
    protected function getTerminalHeight()
    {
        @trigger_error(sprintf('%s is deprecated as of 3.2 and will be removed in 4.0. Create a Terminal instance instead.', __METHOD__), E_USER_DEPRECATED);

        return $this->terminal->getHeight();
    }

    /**
     * Tries to figure out the terminal dimensions based on the current environment.
     *
     * @return array Array containing width and height
     *
     * @deprecated since version 3.2, to be removed in 4.0. Create a Terminal instance instead.
     */
    public function getTerminalDimensions()
    {
        @trigger_error(sprintf('%s is deprecated as of 3.2 and will be removed in 4.0. Create a Terminal instance instead.', __METHOD__), E_USER_DEPRECATED);

        return array($this->terminal->getWidth(), $this->terminal->getHeight());
    }

    /**
     * Sets terminal dimensions.
     *
     * Can be useful to force terminal dimensions for functional tests.
     *
     * @param int $width  The width
     * @param int $height The height
     *
     * @return $this
     *
     * @deprecated since version 3.2, to be removed in 4.0. Set the COLUMNS and LINES env vars instead.
     */
    public function setTerminalDimensions($width, $height)
    {
        @trigger_error(sprintf('%s is deprecated as of 3.2 and will be removed in 4.0. Set the COLUMNS and LINES env vars instead.', __METHOD__), E_USER_DEPRECATED);

        putenv('COLUMNS='.$width);
        putenv('LINES='.$height);

        return $this;
    }

    /**
     * Configures the input and output instances based on the user arguments and options.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     */
    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(array('--ansi'), true)) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(array('--no-ansi'), true)) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(array('--no-interaction', '-n'), true)) {
            $input->setInteractive(false);
        } elseif (function_exists('posix_isatty')) {
            $inputStream = null;

            if ($input instanceof StreamableInputInterface) {
                $inputStream = $input->getStream();
            }

            // This check ensures that calling QuestionHelper::setInputStream() works
            // To be removed in 4.0 (in the same time as QuestionHelper::setInputStream)
            if (!$inputStream && $this->getHelperSet()->has('question')) {
                $inputStream = $this->getHelperSet()->get('question')->getInputStream(false);
            }

            if (!@posix_isatty($inputStream) && false === getenv('SHELL_INTERACTIVE')) {
                $input->setInteractive(false);
            }
        }

        if (true === $input->hasParameterOption(array('--quiet', '-q'), true)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
            $input->setInteractive(false);
        } else {
            if ($input->hasParameterOption('-vvv', true) || $input->hasParameterOption('--verbose=3', true) || $input->getParameterOption('--verbose', false, true) === 3) {
                $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
            } elseif ($input->hasParameterOption('-vv', true) || $input->hasParameterOption('--verbose=2', true) || $input->getParameterOption('--verbose', false, true) === 2) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
            } elseif ($input->hasParameterOption('-v', true) || $input->hasParameterOption('--verbose=1', true) || $input->hasParameterOption('--verbose', true) || $input->getParameterOption('--verbose', false, true)) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
            }
        }
    }

    /**
     * Runs the current command.
     *
     * If an event dispatcher has been attached to the application,
     * events are also dispatched during the life-cycle of the command.
     *
     * @param Command         $command A Command instance
     * @param InputInterface  $input   An Input instance
     * @param OutputInterface $output  An Output instance
     *
     * @return int 0 if everything went fine, or an error code
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        foreach ($command->getHelperSet() as $helper) {
            if ($helper instanceof InputAwareInterface) {
                $helper->setInput($input);
            }
        }

        if (null === $this->dispatcher) {
            return $command->run($input, $output);
        }

        // bind before the console.command event, so the listeners have access to input options/arguments
        try {
            $command->mergeApplicationDefinition();
            $input->bind($command->getDefinition());
        } catch (ExceptionInterface $e) {
            // ignore invalid options/arguments for now, to allow the event listeners to customize the InputDefinition
        }

        $event = new ConsoleCommandEvent($command, $input, $output);
        $e = null;

        try {
            $this->dispatcher->dispatch(ConsoleEvents::COMMAND, $event);

            if ($event->commandShouldRun()) {
                $exitCode = $command->run($input, $output);
            } else {
                $exitCode = ConsoleCommandEvent::RETURN_CODE_DISABLED;
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        if (null !== $e) {
            if ($this->dispatcher->hasListeners(ConsoleEvents::EXCEPTION)) {
                $x = $e instanceof \Exception ? $e : new FatalThrowableError($e);
                $event = new ConsoleExceptionEvent($command, $input, $output, $x, $x->getCode());
                $this->dispatcher->dispatch(ConsoleEvents::EXCEPTION, $event);

                if ($x !== $event->getException()) {
                    $e = $event->getException();
                }
            }
            $event = new ConsoleErrorEvent($input, $output, $e, $command);
            $this->dispatcher->dispatch(ConsoleEvents::ERROR, $event);
            $e = $event->getError();

            if (0 === $exitCode = $event->getExitCode()) {
                $e = null;
            }
        }

        $event = new ConsoleTerminateEvent($command, $input, $output, $exitCode);
        $this->dispatcher->dispatch(ConsoleEvents::TERMINATE, $event);

        if (null !== $e) {
            throw $e;
        }

        return $event->getExitCode();
    }

    /**
     * Gets the name of the command based on input.
     *
     * @param InputInterface $input The input interface
     *
     * @return string The command name
     */
    protected function getCommandName(InputInterface $input)
    {
        return $this->singleCommand ? $this->defaultCommand : $input->getFirstArgument();
    }

    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),

            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new InputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ));
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return Command[] An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        return array(new HelpCommand(), new ListCommand());
    }

    /**
     * Gets the default helper set with the helpers that should always be available.
     *
     * @return HelperSet A HelperSet instance
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet(array(
            new FormatterHelper(),
            new DebugFormatterHelper(),
            new ProcessHelper(),
            new QuestionHelper(),
        ));
    }

    /**
     * Returns abbreviated suggestions in string format.
     *
     * @param array $abbrevs Abbreviated suggestions to convert
     *
     * @return string A formatted string of abbreviated suggestions
     */
    private function getAbbreviationSuggestions($abbrevs)
    {
        return '    '.implode("\n    ", $abbrevs);
    }

    /**
     * Returns the namespace part of the command name.
     *
     * This method is not part of public API and should not be used directly.
     *
     * @param string $name  The full name of the command
     * @param string $limit The maximum number of parts of the namespace
     *
     * @return string The namespace of the command
     */
    public function extractNamespace($name, $limit = null)
    {
        $parts = explode(':', $name);
        array_pop($parts);

        return implode(':', null === $limit ? $parts : array_slice($parts, 0, $limit));
    }

    /**
     * Finds alternative of $name among $collection,
     * if nothing is found in $collection, try in $abbrevs.
     *
     * @param string             $name       The string
     * @param array|\Traversable $collection The collection
     *
     * @return string[] A sorted array of similar string
     */
    private function findAlternatives($name, $collection)
    {
        $threshold = 1e3;
        $alternatives = array();

        $collectionParts = array();
        foreach ($collection as $item) {
            $collectionParts[$item] = explode(':', $item);
        }

        foreach (explode(':', $name) as $i => $subname) {
            foreach ($collectionParts as $collectionName => $parts) {
                $exists = isset($alternatives[$collectionName]);
                if (!isset($parts[$i]) && $exists) {
                    $alternatives[$collectionName] += $threshold;
                    continue;
                } elseif (!isset($parts[$i])) {
                    continue;
                }

                $lev = levenshtein($subname, $parts[$i]);
                if ($lev <= strlen($subname) / 3 || '' !== $subname && false !== strpos($parts[$i], $subname)) {
                    $alternatives[$collectionName] = $exists ? $alternatives[$collectionName] + $lev : $lev;
                } elseif ($exists) {
                    $alternatives[$collectionName] += $threshold;
                }
            }
        }

        foreach ($collection as $item) {
            $lev = levenshtein($name, $item);
            if ($lev <= strlen($name) / 3 || false !== strpos($item, $name)) {
                $alternatives[$item] = isset($alternatives[$item]) ? $alternatives[$item] - $lev : $lev;
            }
        }

        $alternatives = array_filter($alternatives, function ($lev) use ($threshold) { return $lev < 2 * $threshold; });
        ksort($alternatives, SORT_NATURAL | SORT_FLAG_CASE);

        return array_keys($alternatives);
    }

    /**
     * Sets the default Command name.
     *
     * @param string $commandName     The Command name
     * @param bool   $isSingleCommand Set to true if there is only one command in this application
     *
     * @return self
     */
    public function setDefaultCommand($commandName, $isSingleCommand = false)
    {
        $this->defaultCommand = $commandName;

        if ($isSingleCommand) {
            // Ensure the command exist
            $this->find($commandName);

            $this->singleCommand = true;
        }

        return $this;
    }

    private function splitStringByWidth($string, $width)
    {
        // str_split is not suitable for multi-byte characters, we should use preg_split to get char array properly.
        // additionally, array_slice() is not enough as some character has doubled width.
        // we need a function to split string not by character count but by string width
        if (false === $encoding = mb_detect_encoding($string, null, true)) {
            return str_split($string, $width);
        }

        $utf8String = mb_convert_encoding($string, 'utf8', $encoding);
        $lines = array();
        $line = '';
        foreach (preg_split('//u', $utf8String) as $char) {
            // test if $char could be appended to current line
            if (mb_strwidth($line.$char, 'utf8') <= $width) {
                $line .= $char;
                continue;
            }
            // if not, push current line to array and make new line
            $lines[] = str_pad($line, $width);
            $line = $char;
        }
        if ('' !== $line) {
            $lines[] = count($lines) ? str_pad($line, $width) : $line;
        }

        mb_convert_variables($encoding, 'utf8', $lines);

        return $lines;
    }

    /**
     * Returns all namespaces of the command name.
     *
     * @param string $name The full name of the command
     *
     * @return string[] The namespaces of the command
     */
    private function extractAllNamespaces($name)
    {
        // -1 as third argument is needed to skip the command short name when exploding
        $parts = explode(':', $name, -1);
        $namespaces = array();

        foreach ($parts as $part) {
            if (count($namespaces)) {
                $namespaces[] = end($namespaces).':'.$part;
            } else {
                $namespaces[] = $part;
            }
        }

        return $namespaces;
    }
}
