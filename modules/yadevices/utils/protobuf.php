<?php

/**
 * Кодировщик Protobuf на основании https://github.com/ndunks/php-simple-protobuf
 */
class Protobuf {
    protected $fields = [];

    /**
     * Устанавливает строковое значение
     * @param int $fieldNumber Номер поля
     * @param string $value Значение
     */
    public function setString(int $fieldNumber, string $value): void {
        $this->fields[$fieldNumber] = ['type' => 'string', 'value' => $value];
    }

    /**
     * Устанавливает целочисленное значение
     * @param int $fieldNumber Номер поля
     * @param int $value Значение
     */
    public function setInt32(int $fieldNumber, int $value): void {
        $this->fields[$fieldNumber] = ['type' => 'int32', 'value' => $value];
    }

    /**
     * Сериализует сообщение в бинарный формат
     * @return string Бинарные данные
     */
    public function serialize(): string {
        $output = '';
        foreach ($this->fields as $fieldNumber => $field) {
            $wireType = $this->getWireType($field['type']);
            $output .= $this->varint($fieldNumber << 3 | $wireType);
            $output .= $this->encodeField($field['value'], $field['type']);
        }
        return $output;
    }

    /**
     * Кодирует значение в зависимости от типа
     * @param mixed $value Значение
     * @param string $type Тип поля
     * @return string Закодированные данные
     */
    private function encodeField($value, string $type): string {
        switch ($type) {
            case 'string':
                return $this->varint(strlen($value)) . $value;
            case 'int32':
                return $this->varint($value);
            default:
                throw new Exception('Неизвестный тип поля');
        }
    }

    /**
     * Возвращает тип провода для заданного типа поля
     * @param string $type Тип поля
     * @return int Тип провода
     */
    private function getWireType(string $type): int {
        switch ($type) {
            case 'string':
                return 2;
            case 'int32':
                return 0;
            default:
                throw new Exception('Неизвестный тип поля');
        }
    }

    /**
     * Кодирует значение в varint
     * @param int $value Значение
     * @return string Закодированные данные
     */
    private function varint(int $value): string {
        $result = '';
        while (($value & ~0x7F) !== 0) {
            $result .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $result .= chr($value);
        return $result;
    }
}
